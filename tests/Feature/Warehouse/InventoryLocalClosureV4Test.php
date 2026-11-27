<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockExitItemLotAllocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * INVENTORY_LOCAL_FINAL_CLOSURE_V4 — truy vết movement khi sửa phiếu (2.1), A6 qua HTTP (2.2),
 * tìm kiếm/tồn khả dụng form xuất kho khi kho > 30 mã (2.3).
 */
class InventoryLocalClosureV4Test extends TestCase
{
    use RefreshDatabase;

    private StockService $svc;
    private User $user;
    private Warehouse $wh;
    private Supplier $supplier;
    private ProductCategory $cat;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->svc = app(StockService::class);
        $this->user = User::factory()->create();
        $this->user->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        $this->actingAs($this->user);
        foreach (['1331' => 'asset', '1561' => 'asset', '154' => 'asset', '3311' => 'liability', '632' => 'expense'] as $c => $t) {
            AccountCode::firstOrCreate(['code' => $c], ['name' => $c, 'type' => $t, 'normal_balance' => $t === 'liability' ? 'credit' : 'debit', 'is_detail' => true]);
        }
        $this->wh = Warehouse::create(['name' => 'Kho V4']);
        $this->supplier = Supplier::create(['code' => 'NCC-V4', 'name' => 'NCC', 'payable_account_code' => '3311']);
        $this->cat = ProductCategory::create(['name' => 'Cat V4']);
        $this->project = Project::create(['code' => 'DA-V4', 'name' => 'DA', 'status' => 'in_progress',
            'customer_id' => Customer::create(['code' => 'KH-V4', 'name' => 'KH'])->id, 'created_by' => $this->user->id]);
    }

    private function product(string $code, string $name = null): Product
    {
        return Product::create(['code' => $code, 'name' => $name ?? "Hàng {$code}", 'category_id' => $this->cat->id, 'cost_price' => 110000, 'is_active' => true]);
    }

    /** PO đã gửi + phiếu nhập nháp theo PO (mỗi sản phẩm 1 dòng). */
    private function poEntry(array $lines, ?int $projectId = null): array
    {
        $po = PurchaseOrder::create(['code' => 'MH-' . uniqid(), 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->wh->id,
            'project_id' => $projectId, 'status' => PurchaseOrderStatus::Sent, 'order_date' => now()->toDateString(),
            'created_by' => $this->user->id, 'subtotal' => 0, 'tax' => 0, 'total' => 0]);
        $entry = StockEntry::create(['code' => 'NK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id, 'status' => StockEntryStatus::Draft, 'entry_date' => now()->toDateString(), 'created_by' => $this->user->id]);
        foreach ($lines as [$product, $qty]) {
            $poItem = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity' => 20,
                'received_quantity' => 0, 'unit_price' => 100000, 'vat_rate' => 10, 'subtotal' => 2000000, 'project_id' => $projectId]);
            StockEntryItem::create(['stock_entry_id' => $entry->id, 'product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 100000,
                'tax_rate' => 10, 'purchase_order_item_id' => $poItem->id, 'project_id' => $projectId, 'unit_cost' => 100000]);
        }
        return [$po, $entry];
    }

    private function updatePayload(StockEntry $entry, array $lines): array
    {
        return ['code' => $entry->code, 'entry_date' => now()->toDateString(), 'notes' => null,
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => $l[2] ?? 100000, 'tax_rate' => 10], $lines)];
    }

    private function orphanMovements(StockEntry $entry): int
    {
        return StockMovement::where('source_type', StockEntry::class)->where('source_id', $entry->id)
            ->whereNotIn('source_item_id', StockEntryItem::where('stock_entry_id', $entry->id)->pluck('id'))->count();
    }

    /** 2.1: thu hồi → sửa SL/giá → xác nhận lại: dòng giữ nguyên ID, movement gốc/đảo/mới cùng source_item_id. */
    public function test_edit_after_recall_keeps_item_id_and_movement_trace(): void
    {
        $p = $this->product('SP-E1');
        [, $entry] = $this->poEntry([[$p, 5]]);
        $this->svc->confirmEntry($entry);
        $itemId = $entry->items()->value('id');
        $this->svc->recallEntry($entry->fresh());

        $this->put(route('warehouse.stock-entries.update', $entry), $this->updatePayload($entry, [[$p, 4, 120000]]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([$itemId], $entry->items()->pluck('id')->all(), 'Dòng hàng phải giữ nguyên ID');
        $this->assertSame(4.0, (float) StockEntryItem::find($itemId)->quantity);
        $log = DB::table('activity_log')->where('log_name', 'stock_entry_item')->first();
        $this->assertNotNull($log, 'Sửa dòng đã có movement phải ghi lịch sử trước/sau');
        $props = json_decode($log->properties, true);
        $this->assertEquals(5, $props['old']['quantity']);
        $this->assertEquals(4, $props['new']['quantity']);
        $this->assertCount(2, $props['movement_ids'], 'movement gốc + đảo');

        $this->svc->confirmEntry($entry->fresh());
        $this->assertSame(0, $this->orphanMovements($entry));
        $this->assertSame(3, StockMovement::where('source_item_id', $itemId)->where('source_type', StockEntry::class)->count());
        $this->assertEqualsWithDelta(4, (float) InventoryBalance::where('product_id', $p->id)->value('qty_on_hand'), 0.0005);
    }

    /** 2.1: xóa hẳn một dòng đã có movement → snapshot dòng + movement lưu ở activity log; movement không bị xóa. */
    public function test_removing_line_with_history_keeps_snapshot(): void
    {
        [$a, $b] = [$this->product('SP-E2'), $this->product('SP-E3')];
        [, $entry] = $this->poEntry([[$a, 2], [$b, 3]]);
        $this->svc->confirmEntry($entry);
        $removedId = $entry->items()->where('product_id', $b->id)->value('id');
        $this->svc->recallEntry($entry->fresh());
        $mvCount = StockMovement::where('source_id', $entry->id)->where('source_type', StockEntry::class)->count();

        $this->put(route('warehouse.stock-entries.update', $entry), $this->updatePayload($entry, [[$a, 2]]))->assertSessionHasNoErrors();

        $this->assertSame($mvCount, StockMovement::where('source_id', $entry->id)->where('source_type', StockEntry::class)->count());
        $log = DB::table('activity_log')->where('log_name', 'stock_entry_item')->where('description', 'like', "%#{$removedId}%")->first();
        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame($b->id, (int) $props['deleted_item']['product_id']);
        $this->assertEquals(3, $props['deleted_item']['quantity']);
        $this->assertCount(2, $props['movement_ids']);
        $this->assertSame((int) $log->subject_id, $entry->id);
    }

    /** 2.2 / A6 qua HTTP: phiếu nhập dự án đã thu hồi, lô từng phân bổ → sửa bị chặn rõ ràng, lịch sử còn nguyên. */
    public function test_http_edit_of_recalled_project_entry_with_allocated_lot_is_blocked(): void
    {
        $p = $this->product('SP-E4');
        [, $entry] = $this->poEntry([[$p, 5]], $this->project->id);
        $this->svc->confirmEntry($entry);
        $exit = StockExit::create(['code' => 'XK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'project_id' => $this->project->id,
            'issue_purpose' => 'project_cost', 'item_usage_type' => 'project', 'status' => 'draft', 'exit_date' => now()->toDateString(), 'created_by' => $this->user->id]);
        StockExitItem::create(['stock_exit_id' => $exit->id, 'product_id' => $p->id, 'quantity' => 2, 'unit_price' => 0, 'subtotal' => 0]);
        $this->svc->confirmExit($exit);
        $this->svc->cancelExit($exit->fresh());
        $this->svc->recallEntry($entry->fresh());
        [$lots, $allocs, $itemIds] = [ProjectInventoryLot::count(), StockExitItemLotAllocation::count(), $entry->items()->pluck('id')->all()];
        $this->assertGreaterThan(0, $allocs);

        $this->from(route('warehouse.stock-entries.edit', $entry))
            ->put(route('warehouse.stock-entries.update', $entry), $this->updatePayload($entry, [[$p, 3]]))
            ->assertRedirect(route('warehouse.stock-entries.edit', $entry))
            ->assertSessionHasErrors(['items' => StockEntry::LOT_HISTORY_EDIT_BLOCKED]);

        $this->assertSame($lots, ProjectInventoryLot::count());
        $this->assertSame($allocs, StockExitItemLotAllocation::count());
        $this->assertSame($itemIds, $entry->items()->pluck('id')->all());
        $this->assertSame(5.0, (float) $entry->items()->value('quantity'));
    }

    /** 2.1: phiếu nhập thủ công (Nhập khác Nợ 156/Có 154 không dự án) — sửa sau thu hồi giữ ID dòng. */
    public function test_manual_other_entry_edit_keeps_item_id(): void
    {
        $p = $this->product('SP-M1');
        $payload = fn (int $qty) => ['kind' => 'other', 'code' => 'NK-M1', 'entry_date' => now()->toDateString(), 'warehouse_id' => $this->wh->id,
            'credit_account_code' => '154', 'items' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_price' => 50000, 'tax_rate' => 0]]];
        $this->post(route('warehouse.stock-entries.manual.store'), $payload(3))->assertSessionHasNoErrors();
        $entry = StockEntry::where('code', 'NK-M1')->firstOrFail();
        $itemId = $entry->items()->value('id');
        $this->svc->confirmEntry($entry);
        $this->svc->recallEntry($entry->fresh());

        $this->put(route('warehouse.stock-entries.manual.update', $entry), $payload(2))->assertSessionHasNoErrors();
        $this->assertSame([$itemId], $entry->items()->pluck('id')->all());
        $this->svc->confirmEntry($entry->fresh());
        $this->assertSame(0, $this->orphanMovements($entry));
        $this->assertEqualsWithDelta(2, (float) InventoryBalance::where('product_id', $p->id)->value('qty_on_hand'), 0.0005);
    }

    private function seedGeneralStock(int $n, string $prefix): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $p = $this->product(sprintf('%s%03d', $prefix, $i), "Vật tư {$i}");
            InventoryBalance::create(['product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'qty_on_hand' => $i, 'value_on_hand' => $i * 1000, 'avg_cost' => 1000]);
            $out[] = $p;
        }
        return $out;
    }

    private function search(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->wh->id] + $params))->assertOk();
    }

    /** 2.3: kho 35 mã — không bỏ sót, sắp theo mã, SKU chính xác đứng đầu, tìm theo tên. */
    public function test_search_over_30_products_sorted_exact_sku_first_and_by_name(): void
    {
        $this->seedGeneralStock(35, 'VT-');
        $res = $this->search([]);
        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertCount(35, $codes);
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);
        $this->assertFalse($res->json('meta.has_more'));

        $this->product('VT-01', 'Mã ngắn');
        InventoryBalance::create(['product_id' => Product::where('code', 'VT-01')->value('id'), 'warehouse_id' => $this->wh->id, 'qty_on_hand' => 1, 'value_on_hand' => 5, 'avg_cost' => 5]);
        $data = $this->search(['q' => 'vt-01'])->json('data');
        $this->assertSame('VT-01', $data[0]['code'], 'Khớp chính xác SKU phải đứng đầu');
        $this->assertGreaterThan(1, count($data));

        $data = $this->search(['q' => 'VẬT TƯ 3'])->json('data');
        $this->assertEqualsCanonicalizing(['VT-003', 'VT-030', 'VT-031', 'VT-032', 'VT-033', 'VT-034', 'VT-035'], array_column($data, 'code'));
    }

    /** 2.3: > 1 trang — trang 2 trả phần còn lại, tổng không trùng/không sót. */
    public function test_search_pagination_returns_all_products(): void
    {
        $this->seedGeneralStock(205, 'PG-');
        $p1 = $this->search([]);
        $this->assertCount(200, $p1->json('data'));
        $this->assertTrue($p1->json('meta.has_more'));
        $this->assertSame(205, $p1->json('meta.total'));
        $p2 = $this->search(['page' => 2])->json('data');
        $this->assertCount(5, $p2);
        $all = array_merge(array_column($p1->json('data'), 'value'), array_column($p2, 'value'));
        $this->assertCount(205, array_unique($all));
    }

    /** 2.3: chế độ dự án — lô FIFO + kho chung (cả SP chưa có AVCO) cùng nguồn với backend; xuất đúng số hiển thị được. */
    public function test_project_search_matches_backend_availability(): void
    {
        $lotOnly = $this->product('DA-LOT');
        [, $e1] = $this->poEntry([[$lotOnly, 4]], $this->project->id);
        $this->svc->confirmEntry($e1);
        $general = $this->product('DA-GEN');
        [, $e2] = $this->poEntry([[$general, 6]]);
        $this->svc->confirmEntry($e2);
        $legacy = $this->product('DA-LEG');
        StockMovement::create(['product_id' => $legacy->id, 'warehouse_id' => $this->wh->id, 'type' => 'in', 'quantity' => 2,
            'source_type' => StockEntry::class, 'source_id' => 0, 'created_by' => $this->user->id, 'status' => 'active']);

        $data = collect($this->search(['project_id' => $this->project->id])->json('data'))->keyBy('code');
        $this->assertEquals(4, $data['DA-LOT']['qty']);
        $this->assertEquals(6, $data['DA-GEN']['qty']);
        $this->assertEquals(2, $data['DA-LEG']['qty'], 'SP chưa có AVCO: backend dùng fallback movement → search cũng phải hiện');

        $exit = StockExit::create(['code' => 'XK-' . uniqid(), 'warehouse_id' => $this->wh->id, 'project_id' => $this->project->id,
            'issue_purpose' => 'project_cost', 'item_usage_type' => 'project', 'status' => 'draft', 'exit_date' => now()->toDateString(), 'created_by' => $this->user->id]);
        foreach (['DA-LOT', 'DA-GEN'] as $code) {
            StockExitItem::create(['stock_exit_id' => $exit->id, 'product_id' => $data[$code]['value'], 'quantity' => $data[$code]['qty'], 'unit_price' => 0, 'subtotal' => 0]);
        }
        $this->svc->confirmExit($exit);
        $this->assertSame('confirmed', $exit->fresh()->status->value ?? $exit->fresh()->status);
        $left = collect($this->search(['project_id' => $this->project->id])->json('data'))->pluck('code')->all();
        $this->assertSame(['DA-LEG'], $left);
    }
}
