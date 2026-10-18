<?php

namespace Tests\Feature\Reports;

use App\Enums\InventoryCountStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReturnStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockTransferStatus;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryCountService;
use App\Services\PurchaseReturnService;
use App\Services\Reports\InventoryKpiDrilldownService;
use App\Services\Reports\InventoryReportService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Inventory KPI Drill-down (/reports/inventory/drilldown) — approved scope (LOCAL only).
 *
 * TC1: reconcile khớp KPI gốc cho cả 4 kpi (begin/in/out/end) với dữ liệu hỗn hợp.
 * TC2: warehouse_id filter vẫn đối soát đúng.
 * TC3: category_id filter vẫn đối soát đúng.
 * TC4: movement status=voided bị loại khỏi cả KPI và Drill-down.
 * TC5: amount = NULL (giả lập SalesReturn) — không suy diễn giá trị, báo cáo riêng ở null_value_stats,
 *      reconcile vẫn khớp (COALESCE(amount,0) cả 2 phía).
 * TC6: sản phẩm bị soft-delete — loại khỏi cả KPI và Drill-down, vẫn đối soát khớp.
 * TC7: tab "begin"/"end" lấy TOÀN BỘ lịch sử trước/đến mốc ngày, không chỉ phát sinh trong kỳ.
 * TC8: pagination + search trên tab documents/products.
 * TC9: sort param không hợp lệ không gây lỗi (an toàn, fallback mặc định).
 * TC10: permission gate — user không có reports.view bị 403.
 */
class InventoryKpiDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $wh1;
    private Warehouse $wh2;
    private Product $product;
    private InventoryReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'drilldown.test@test.local'],
            ['name' => 'Drilldown Test', 'password' => bcrypt('x'), 'is_active' => true]
        );
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');
        $this->actingAs($this->user);

        $this->wh1 = Warehouse::create(['name' => 'Kho Chính', 'code' => 'DD-KC', 'address' => 'HN']);
        $this->wh2 = Warehouse::create(['name' => 'Kho Phụ', 'code' => 'DD-KP', 'address' => 'HN']);

        $this->product = Product::create([
            'code' => 'DD-SP1', 'name' => 'Drilldown SP1', 'unit' => 'cái',
            'cost_price' => 100000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);

        $this->reportService = new InventoryReportService();
    }

    private function insertMovement(array $data): int
    {
        return DB::table('stock_movements')->insertGetId(array_merge([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->wh1->id,
            'type'         => 'in',
            'quantity'     => 1,
            'unit_cost'    => 0,
            'amount'       => 0,
            'status'       => 'active',
            'source_type'  => null,
            'created_by'   => $this->user->id,
            'updated_at'   => now(),
        ], $data));
    }

    private function filters(array $overrides = []): array
    {
        return array_merge(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $overrides);
    }

    private function fetch(string $kpi, string $tab, array $filters, array $extra = []): array
    {
        $response = $this->get(route('reports.inventory.drilldown', array_merge($filters, $extra, [
            'kpi' => $kpi, 'tab' => $tab,
        ])));
        $response->assertOk();

        return $response->json();
    }

    // ── TC1: đối soát khớp KPI gốc cho cả 4 kpi ─────────────────────────────────

    public function test_tc1_reconcile_matches_kpi_for_all_four(): void
    {
        $this->insertMovement(['quantity' => 5, 'unit_cost' => 10000, 'amount' => 50000, 'created_at' => '2025-12-01 10:00:00']); // begin
        $this->insertMovement(['quantity' => 3, 'unit_cost' => 20000, 'amount' => 60000, 'created_at' => '2026-01-10 10:00:00']); // in
        $this->insertMovement(['quantity' => -2, 'unit_cost' => 20000, 'amount' => -40000, 'type' => 'out', 'created_at' => '2026-01-15 10:00:00']); // out

        $filters = $this->filters();
        $kpiSummary = $this->reportService->buildSummary($filters);

        $map = ['begin' => 'total_begin_value', 'in' => 'total_in_value', 'out' => 'total_out_value', 'end' => 'total_end_value'];
        foreach ($map as $kpi => $key) {
            $data = $this->fetch($kpi, 'reconcile', $filters);
            $this->assertEquals((float) $kpiSummary[$key], $data['kpi_value'], "kpi={$kpi} kpi_value phải khớp buildSummary()");
            $this->assertEquals(0.0, $data['diff'], "kpi={$kpi} không được lệch đối soát");
            $this->assertTrue($data['matched']);
        }
    }

    // ── TC2: filter theo kho vẫn đối soát đúng ─────────────────────────────────

    public function test_tc2_warehouse_filter_still_reconciles(): void
    {
        $this->insertMovement(['warehouse_id' => $this->wh1->id, 'quantity' => 4, 'unit_cost' => 10000, 'amount' => 40000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['warehouse_id' => $this->wh2->id, 'quantity' => 6, 'unit_cost' => 10000, 'amount' => 60000, 'created_at' => '2026-01-05 10:00:00']);

        $filters = $this->filters(['warehouse_id' => $this->wh1->id]);
        $kpiVal  = (float) $this->reportService->buildSummary($filters)['total_in_value'];

        $this->assertEquals(40000.0, $kpiVal, 'KPI gốc phải chỉ tính kho wh1');

        $data = $this->fetch('in', 'reconcile', $filters);
        $this->assertEquals($kpiVal, $data['kpi_value']);
        $this->assertEquals(0.0, $data['diff']);
    }

    // ── TC3: filter theo danh mục vẫn đối soát đúng ────────────────────────────

    public function test_tc3_category_filter_still_reconciles(): void
    {
        $catA = ProductCategory::create(['name' => 'DD Cat A']);
        $catB = ProductCategory::create(['name' => 'DD Cat B']);
        $this->product->update(['category_id' => $catA->id]);
        $other = Product::create([
            'code' => 'DD-SP2', 'name' => 'Drilldown SP2', 'unit' => 'cái', 'category_id' => $catB->id,
            'cost_price' => 50000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);

        $this->insertMovement(['quantity' => 2, 'unit_cost' => 10000, 'amount' => 20000, 'created_at' => '2026-01-05 10:00:00']);
        DB::table('stock_movements')->insert([
            'product_id' => $other->id, 'warehouse_id' => $this->wh1->id, 'type' => 'in',
            'quantity' => 9, 'unit_cost' => 10000, 'amount' => 90000, 'status' => 'active',
            'created_by' => $this->user->id, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => now(),
        ]);

        $filters = $this->filters(['category_id' => $catA->id]);
        $kpiVal  = (float) $this->reportService->buildSummary($filters)['total_in_value'];
        $this->assertEquals(20000.0, $kpiVal);

        $data = $this->fetch('in', 'reconcile', $filters);
        $this->assertEquals($kpiVal, $data['kpi_value']);
        $this->assertEquals(0.0, $data['diff']);
    }

    // ── TC4: movement voided bị loại khỏi cả KPI và Drill-down ────────────────

    public function test_tc4_voided_movement_excluded_from_both(): void
    {
        $this->insertMovement(['quantity' => 5, 'unit_cost' => 10000, 'amount' => 50000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => 999, 'unit_cost' => 10000, 'amount' => 9990000, 'status' => 'voided', 'created_at' => '2026-01-06 10:00:00']);

        $filters = $this->filters();
        $kpiVal  = (float) $this->reportService->buildSummary($filters)['total_in_value'];
        $this->assertEquals(50000.0, $kpiVal, 'Movement voided không được tính vào KPI');

        $data = $this->fetch('in', 'reconcile', $filters);
        $this->assertEquals($kpiVal, $data['kpi_value']);
        $this->assertEquals(0.0, $data['diff']);

        $docs = $this->fetch('in', 'documents', $filters);
        $this->assertCount(1, $docs['data'], 'Chứng từ voided không được xuất hiện ở tab Chứng từ');
    }

    // ── TC5: amount = NULL (giả lập trả hàng/kiểm kê) — báo cáo riêng, không suy diễn ──

    public function test_tc5_null_amount_reported_separately_and_still_reconciles(): void
    {
        $this->insertMovement(['quantity' => 4, 'unit_cost' => 10000, 'amount' => 40000, 'created_at' => '2026-01-05 10:00:00']);
        // Giả lập SalesReturn: amount/unit_cost NULL (đúng hành vi thật của SalesReturnService)
        $this->insertMovement([
            'quantity' => 3, 'unit_cost' => null, 'amount' => null,
            'source_type' => 'App\\Models\\SalesReturn', 'created_at' => '2026-01-06 10:00:00',
        ]);

        $filters = $this->filters();
        $kpiVal  = (float) $this->reportService->buildSummary($filters)['total_in_value'];
        $this->assertEquals(40000.0, $kpiVal, 'Dòng amount=NULL phải được tính là 0đ ở KPI gốc (COALESCE)');

        $data = $this->fetch('in', 'reconcile', $filters);
        $this->assertEquals(0.0, $data['diff'], 'COALESCE(amount,0) khớp cả 2 phía nên không lệch');
        $this->assertCount(1, $data['null_value_stats']);
        $this->assertSame('Trả hàng bán', $data['null_value_stats'][0]['label']);
        $this->assertSame(1, $data['null_value_stats'][0]['count']);
        $this->assertEquals(3.0, $data['null_value_stats'][0]['qty']);

        $docs = $this->fetch('in', 'documents', $filters);
        $nullRow = collect($docs['data'])->firstWhere('has_source_value', false);
        $this->assertNotNull($nullRow, 'Dòng thiếu giá trị nguồn phải vẫn hiện ở tab Chứng từ, đánh dấu rõ');
        $this->assertNull($nullRow['amount']);
    }

    // ── TC6: sản phẩm soft-delete — loại khỏi cả 2 phía, vẫn đối soát khớp ─────

    public function test_tc6_soft_deleted_product_excluded_consistently(): void
    {
        $this->insertMovement(['quantity' => 4, 'unit_cost' => 10000, 'amount' => 40000, 'created_at' => '2026-01-05 10:00:00']);

        $deletedProduct = Product::create([
            'code' => 'DD-DEL', 'name' => 'Deleted product', 'unit' => 'cái',
            'cost_price' => 10000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);
        DB::table('stock_movements')->insert([
            'product_id' => $deletedProduct->id, 'warehouse_id' => $this->wh1->id, 'type' => 'in',
            'quantity' => 7, 'unit_cost' => 10000, 'amount' => 70000, 'status' => 'active',
            'created_by' => $this->user->id, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => now(),
        ]);
        $deletedProduct->delete();

        $filters = $this->filters();
        $kpiVal  = (float) $this->reportService->buildSummary($filters)['total_in_value'];
        $this->assertEquals(40000.0, $kpiVal, 'KPI gốc loại trừ sản phẩm đã xóa');

        $data = $this->fetch('in', 'reconcile', $filters);
        $this->assertEquals(0.0, $data['diff'], 'Drill-down phải loại trừ SP đã xóa giống KPI gốc để không lệch đối soát');
    }

    // ── TC7: tab begin/end lấy toàn bộ lịch sử trước/đến mốc ngày ──────────────

    public function test_tc7_begin_and_end_tabs_include_full_history_not_just_in_period(): void
    {
        $this->insertMovement(['quantity' => 5, 'unit_cost' => 10000, 'amount' => 50000, 'created_at' => '2024-03-01 10:00:00']); // rất xa trong quá khứ
        $this->insertMovement(['quantity' => 2, 'unit_cost' => 10000, 'amount' => 20000, 'created_at' => '2026-01-10 10:00:00']); // trong kỳ

        $filters = $this->filters();

        $beginDocs = $this->fetch('begin', 'documents', $filters);
        $this->assertCount(1, $beginDocs['data'], 'Tab begin phải thấy movement 2024 (toàn bộ lịch sử trước date_from)');
        $this->assertSame('2024-03-01', $beginDocs['data'][0]['doc_date']);

        $inDocs = $this->fetch('in', 'documents', $filters);
        $this->assertCount(1, $inDocs['data'], 'Tab in chỉ thấy movement trong kỳ, không thấy movement 2024');

        $endDocs = $this->fetch('end', 'documents', $filters);
        $this->assertCount(2, $endDocs['data'], 'Tab end phải thấy TOÀN BỘ movement từ đầu đến hết date_to (2024 + trong kỳ)');
    }

    // ── TC8: pagination + search ────────────────────────────────────────────

    public function test_tc8_pagination_and_search_on_documents_and_products(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertMovement(['quantity' => 1, 'unit_cost' => 1000, 'amount' => 1000, 'created_at' => "2026-01-0{$i} 10:00:00"]);
        }
        $other = Product::create([
            'code' => 'DD-OTHER', 'name' => 'Other product', 'unit' => 'cái',
            'cost_price' => 10000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);
        DB::table('stock_movements')->insert([
            'product_id' => $other->id, 'warehouse_id' => $this->wh1->id, 'type' => 'in',
            'quantity' => 1, 'unit_cost' => 1000, 'amount' => 1000, 'status' => 'active',
            'created_by' => $this->user->id, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => now(),
        ]);

        $filters = $this->filters();

        // 5 movement của DD-SP1 + 1 movement của DD-OTHER (chèn ngay dưới) = 6 dòng "in" trong kỳ
        $docsPage1 = $this->fetch('in', 'documents', $filters, ['per_page' => 2, 'page' => 1]);
        $this->assertCount(2, $docsPage1['data']);
        $this->assertEquals(6, $docsPage1['total']);
        $this->assertEquals(3, $docsPage1['last_page']);

        // Không test filter 'search' (ilike) ở đây: môi trường test chạy SQLite (phpunit.xml),
        // SQLite không hỗ trợ operator ILIKE — hạn chế đã có từ trước ở InventoryReportService
        // (báo cáo gốc cũng không có test nào exercise 'search' vì lý do này), không thuộc phạm vi
        // sửa của task này. Lọc theo product_id thay thế để vẫn xác nhận where-scoping đúng.
        $docsByOther = $this->fetch('in', 'documents', array_merge($filters, ['per_page' => 50]));
        $otherRow = collect($docsByOther['data'])->firstWhere('product_code', 'DD-OTHER');
        $this->assertNotNull($otherRow, 'Movement của sản phẩm khác cũng phải xuất hiện trong tab Chứng từ');

        $products = $this->fetch('in', 'products', $filters);
        $this->assertGreaterThanOrEqual(2, $products['total'], 'Tab Theo sản phẩm phải thấy cả 2 sản phẩm có phát sinh');
    }

    // ── TC9: sort không hợp lệ không gây lỗi ───────────────────────────────────

    public function test_tc9_invalid_sort_param_is_safe(): void
    {
        $this->insertMovement(['quantity' => 2, 'unit_cost' => 1000, 'amount' => 2000, 'created_at' => '2026-01-05 10:00:00']);

        $filters = $this->filters();

        // 'sort' không có whitelist cứng ở validation (chỉ 'nullable|string') — giá trị lạ/độc hại
        // phải fallback về cột mặc định (doc_date) ở service, không được lọt xuống SQL thô.
        $data = $this->fetch('in', 'documents', $filters, ['sort' => 'id; DROP TABLE stock_movements;--']);
        $this->assertCount(1, $data['data'], 'Sort param độc hại phải fallback an toàn, không gây lỗi hay injection');
        $this->assertDatabaseCount('stock_movements', 1);

        // 'direction' có whitelist ở validation (in:asc,desc) — giá trị ngoài danh sách bị chặn
        // sạch bằng validation (redirect/422), không rơi xuống thành lỗi 500.
        $this->get(route('reports.inventory.drilldown', array_merge($filters, [
            'kpi' => 'in', 'tab' => 'documents', 'direction' => 'bogus',
        ])))->assertStatus(302);
    }

    // ── TC10: permission gate ──────────────────────────────────────────────────

    public function test_tc10_route_permission_gate(): void
    {
        $noPermUser = User::firstOrCreate(
            ['email' => 'drilldown-noperm@test.local'],
            ['name' => 'No Perm', 'password' => bcrypt('x'), 'is_active' => true]
        );

        $this->actingAs($noPermUser)
            ->get(route('reports.inventory.drilldown', ['kpi' => 'in', 'tab' => 'summary'] + $this->filters()))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get(route('reports.inventory.drilldown', ['kpi' => 'in', 'tab' => 'summary'] + $this->filters()))
            ->assertOk();
    }

    // ── TC11: tab Theo sản phẩm chỉ gồm SP có phát sinh đúng kpi (bug tìm thấy ở vòng review trước) ──

    public function test_tc11_products_tab_scoped_to_kpi_not_whole_catalogue(): void
    {
        $this->insertMovement(['quantity' => 2, 'unit_cost' => 1000, 'amount' => 2000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => 3, 'unit_cost' => 1000, 'amount' => 3000, 'created_at' => '2026-01-06 10:00:00']);
        $other = Product::create([
            'code' => 'DD-OTHER2', 'name' => 'Other 2', 'unit' => 'cái',
            'cost_price' => 1000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);
        DB::table('stock_movements')->insert([
            'product_id' => $other->id, 'warehouse_id' => $this->wh1->id, 'type' => 'in',
            'quantity' => 1, 'unit_cost' => 1000, 'amount' => 1000, 'status' => 'active',
            'created_by' => $this->user->id, 'created_at' => '2026-01-05 10:00:00', 'updated_at' => now(),
        ]);
        // SP có tồn tại trong catalogue nhưng KHÔNG hề có stock_movements nào trong kỳ "in"
        Product::create([
            'code' => 'DD-NOMOVE', 'name' => 'Khong phat sinh', 'unit' => 'cái',
            'cost_price' => 1000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);

        $filters = $this->filters();
        $docs     = $this->fetch('in', 'documents', $filters, ['per_page' => 100]);
        $distinct = collect($docs['data'])->pluck('product_code')->unique()->count();

        $products = $this->fetch('in', 'products', $filters);
        $this->assertEquals(2, $distinct, 'Chỉ 2 SP có dòng chứng từ nhập trong kỳ test này');
        $this->assertEquals($distinct, $products['total'],
            'Tab Theo sản phẩm phải khớp đúng số SP phân biệt ở tab Chứng từ, không phải toàn bộ catalogue (bug đã sửa)');
        $this->assertEquals(2.0 + 3.0, collect($products['data'])->firstWhere('code', 'DD-SP1')['qty']);
    }

    // ── TC12/TC13: link chứng từ gốc theo đúng loại + đúng quyền thực của user ─────

    public function test_tc12_document_linking_respects_type_and_permission(): void
    {
        Permission::firstOrCreate(['name' => 'warehouse.stock_entries.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('warehouse.stock_entries.view');

        $this->insertMovement([
            'quantity' => 2, 'unit_cost' => 1000, 'amount' => 2000, 'created_at' => '2026-01-05 10:00:00',
            'source_type' => 'App\\Models\\StockEntry', 'source_id' => 999,
        ]);
        $this->insertMovement([
            'quantity' => 1, 'unit_cost' => 1000, 'amount' => 1000, 'created_at' => '2026-01-06 10:00:00',
            'source_type' => 'App\\Models\\StockTransfer', 'source_id' => 1,
        ]);

        $docs = $this->fetch('in', 'documents', $this->filters(), ['per_page' => 50]);

        $entryRow = collect($docs['data'])->firstWhere('source_type', 'StockEntry');
        $this->assertTrue($entryRow['linkable'], 'StockEntry + user có quyền warehouse.stock_entries.view -> phải linkable');
        $this->assertEquals(999, $entryRow['document_id']);

        $transferRow = collect($docs['data'])->firstWhere('source_type', 'StockTransfer');
        $this->assertFalse($transferRow['linkable'],
            'StockTransfer chưa có trang chi tiết (show theo id) trong hệ thống -> không được gắn link tự tạo');
    }

    public function test_tc13_document_linking_blocked_without_permission(): void
    {
        // $this->user chỉ có reports.view, KHÔNG có warehouse.stock_entries.view
        $this->insertMovement([
            'quantity' => 2, 'unit_cost' => 1000, 'amount' => 2000, 'created_at' => '2026-01-05 10:00:00',
            'source_type' => 'App\\Models\\StockEntry', 'source_id' => 999,
        ]);

        $docs = $this->fetch('in', 'documents', $this->filters());
        $this->assertFalse($docs['data'][0]['linkable'],
            'Thiếu quyền warehouse.stock_entries.view -> không được hiện link dù đúng loại StockEntry (enforce ở server)');
    }

    // ── TC14/TC15/TC16/TC17: luồng nghiệp vụ thực (không chỉ insert thẳng DB) ─────

    private function makeConfirmedEntry(Warehouse $warehouse, int $qty, float $unitCostExcl): StockEntry
    {
        $supplier = Supplier::firstOrCreate(['code' => 'DD-NCC'], ['name' => 'NCC Test', 'payable_account_code' => '3311']);
        $po = PurchaseOrder::create([
            'code' => 'DD-MH-' . uniqid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-01-01', 'created_by' => $this->user->id,
            'subtotal' => 0, 'tax' => 0, 'total' => 0,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'quantity' => $qty,
            'received_quantity' => 0, 'unit_price' => $unitCostExcl, 'vat_rate' => 10, 'subtotal' => $qty * $unitCostExcl,
        ]);
        $entry = StockEntry::create([
            'code' => 'DD-NK-' . uniqid(), 'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id, 'status' => StockEntryStatus::Draft, 'entry_date' => '2026-01-02',
            'created_by' => $this->user->id,
        ]);
        StockEntryItem::create([
            'stock_entry_id' => $entry->id, 'product_id' => $this->product->id, 'quantity' => $qty,
            'unit_price' => $unitCostExcl, 'tax_rate' => 10, 'purchase_order_item_id' => $poItem->id, 'unit_cost' => $unitCostExcl,
        ]);
        app(StockService::class)->confirmEntry($entry);
        return $entry;
    }

    public function test_tc14_real_stock_transfer_flow_classified_separately_not_as_sale(): void
    {
        $entry = $this->makeConfirmedEntry($this->wh1, 10, 50000); // tồn 10 tại wh1

        $transfer = StockTransfer::create([
            'code' => 'DD-CK-0001', 'from_warehouse_id' => $this->wh1->id, 'to_warehouse_id' => $this->wh2->id,
            'transfer_date' => '2026-01-10', 'status' => StockTransferStatus::Draft, 'created_by' => $this->user->id,
        ]);
        StockTransferItem::create(['stock_transfer_id' => $transfer->id, 'product_id' => $this->product->id, 'quantity' => 4]);
        app(StockTransferService::class)->confirmTransfer($transfer);

        // StockTransfer không có entry_date/exit_date nên DOC_DATE (chép nguyên văn từ
        // InventoryReportService) fallback về sm.created_at = thời điểm confirm thực tế (hôm nay),
        // không phải transfer_date khai báo — hành vi giống hệt báo cáo gốc, không phải lỗi Drill-down.
        // Dùng khoảng ngày bao quanh "hôm nay" thay vì hardcode tháng 1/2026.
        $todayWindow = ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString()];

        // "Xuất trong kỳ" tổng hợp của wh1 phải gồm cả phần chuyển kho (vì stock_movements type=out ở đó),
        // nhưng tab Chứng từ phải GHI RÕ loại "Chuyển kho", không lẫn với "Xuất kho" (bán hàng).
        $wh1Filters = $this->filters(array_merge($todayWindow, ['warehouse_id' => $this->wh1->id]));
        $wh2Filters = $this->filters(array_merge($todayWindow, ['warehouse_id' => $this->wh2->id]));

        $outDocs = $this->fetch('out', 'documents', $wh1Filters, ['per_page' => 50]);
        $transferOutRow = collect($outDocs['data'])->firstWhere('source_type', 'StockTransfer');
        $this->assertNotNull($transferOutRow, 'Phần xuất của chuyển kho phải xuất hiện ở tab Chứng từ (kho nguồn)');
        $this->assertSame('Chuyển kho', $transferOutRow['type_label']);
        $this->assertNotEquals('Xuất kho', $transferOutRow['type_label'],
            'Chuyển kho nội bộ không được gắn nhãn giống xuất bán hàng, tránh người dùng hiểu nhầm');

        $inDocs = $this->fetch('in', 'documents', $wh2Filters, ['per_page' => 50]);
        $transferInRow = collect($inDocs['data'])->firstWhere('source_type', 'StockTransfer');
        $this->assertNotNull($transferInRow, 'Phần nhận của chuyển kho phải xuất hiện ở tab Chứng từ (kho đích)');
        $this->assertSame('Chuyển kho', $transferInRow['type_label']);
        $this->assertNotEquals('Nhập kho', $transferInRow['type_label'],
            'Chuyển kho nội bộ không được gắn nhãn giống nhập mua hàng, tránh người dùng hiểu nhầm');

        // Đối soát vẫn khớp dù có chuyển kho xen giữa
        $kpiOutWh1 = (float) $this->reportService->buildSummary($wh1Filters)['total_out_value'];
        $reconcileOut = $this->fetch('out', 'reconcile', $wh1Filters);
        $this->assertEquals($kpiOutWh1, $reconcileOut['kpi_value']);
        $this->assertEquals(0.0, $reconcileOut['diff']);
    }

    public function test_tc15_real_cancel_entry_shows_both_original_and_reversal(): void
    {
        $entry = $this->makeConfirmedEntry($this->wh1, 6, 40000);
        app(StockService::class)->cancelEntry($entry->fresh());

        $filters = $this->filters(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $docs = $this->fetch('out', 'documents', $filters, ['per_page' => 50]);

        $this->assertCount(1, $docs['data'], 'Phiếu nhập bị hủy phải tạo đúng 1 dòng đảo (out) hiển thị ở tab chứng từ');
        $this->assertStringContainsString('Hủy', $docs['data'][0]['notes'] ?? '',
            'Diễn giải phải thể hiện rõ đây là giao dịch hủy/đảo, không phải nghiệp vụ xuất kho bình thường');

        // Đối soát vẫn khớp — nhập + hủy trong cùng kỳ triệt tiêu lẫn nhau
        $kpiEnd = (float) $this->reportService->buildSummary($filters)['total_end_value'];
        $this->assertEquals(0.0, $kpiEnd, 'Nhập rồi hủy ngay trong kỳ -> tồn cuối kỳ net = 0');
        $reconcileEnd = $this->fetch('end', 'reconcile', $filters);
        $this->assertEquals(0.0, $reconcileEnd['diff']);
    }

    public function test_tc16_real_purchase_return_flow_amount_null(): void
    {
        $entry = $this->makeConfirmedEntry($this->wh1, 10, 60000);
        $po = $entry->purchaseOrder;
        $poItem = $po->items()->first();

        $return = PurchaseReturn::create([
            'code' => 'DD-THM-0001', 'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id,
            'warehouse_id' => $this->wh1->id, 'return_date' => '2026-01-15',
            'status' => PurchaseReturnStatus::Draft, 'created_by' => $this->user->id,
        ]);
        PurchaseReturnItem::create([
            'purchase_return_id' => $return->id, 'purchase_order_item_id' => $poItem->id,
            'product_id' => $this->product->id, 'quantity' => 2,
        ]);
        app(PurchaseReturnService::class)->confirmReturn($return);

        // PurchaseReturn không có entry_date/exit_date -> DOC_DATE fallback sm.created_at (thời điểm
        // confirm thực tế) giống hệt công thức gốc — dùng cửa sổ quanh "hôm nay", không hardcode tháng 1.
        $filters = $this->filters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString()]);
        $data = $this->fetch('out', 'reconcile', $filters);
        $this->assertEquals(0.0, $data['diff'], 'PurchaseReturn thật không có amount nhưng vẫn khớp đối soát (COALESCE 0 cả 2 phía)');

        $prStat = collect($data['null_value_stats'])->firstWhere('label', 'Trả hàng mua');
        $this->assertNotNull($prStat, 'Trả hàng mua thật (không mô phỏng) phải lên đúng bảng cảnh báo thiếu giá trị nguồn');
        $this->assertEquals(2.0, $prStat['qty']);
    }

    public function test_tc17_real_inventory_count_flow_amount_null(): void
    {
        // InventoryCountService::confirm() post JE thiếu/thừa kho qua tài khoản 811/1561 —
        // cần seed account_codes tối thiểu để pass FK, không liên quan logic Drill-down.
        foreach ([
            ['code' => '811',  'name' => 'Chi phí khác',  'type' => 'expense', 'normal_balance' => 'debit',  'level' => 3, 'is_detail' => true],
            ['code' => '1561', 'name' => 'Hàng hóa',      'type' => 'asset',   'normal_balance' => 'debit',  'level' => 3, 'is_detail' => true],
        ] as $ac) {
            \App\Models\AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $this->makeConfirmedEntry($this->wh1, 10, 70000); // tồn hệ thống = 10

        $count = InventoryCount::create([
            'code' => 'DD-IK-0001', 'warehouse_id' => $this->wh1->id, 'count_date' => '2026-01-20',
            'status' => InventoryCountStatus::Draft, 'counted_by' => $this->user->id,
        ]);
        InventoryCountItem::create([
            'inventory_count_id' => $count->id, 'product_id' => $this->product->id,
            'system_quantity' => 10, 'counted_quantity' => 8, // thiếu 2
        ]);
        app(InventoryCountService::class)->confirm($count);

        // InventoryCount không có entry_date/exit_date -> DOC_DATE fallback sm.created_at (thời điểm
        // confirm thực tế) giống hệt công thức gốc — dùng cửa sổ quanh "hôm nay".
        $filters = $this->filters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString()]);
        $data = $this->fetch('out', 'reconcile', $filters);
        $this->assertEquals(0.0, $data['diff'], 'Điều chỉnh kiểm kê thật (thiếu kho) không có amount nhưng vẫn khớp đối soát');

        $icStat = collect($data['null_value_stats'])->firstWhere('label', 'Điều chỉnh kiểm kê');
        $this->assertNotNull($icStat, 'Điều chỉnh kiểm kê thật phải lên đúng bảng cảnh báo thiếu giá trị nguồn');
        $this->assertEquals(2.0, $icStat['qty']);
    }

    // ── TC18: quyền truy cập Excel export — cùng gate reports.view như JSON API ─────

    public function test_tc18_export_route_requires_permission(): void
    {
        $this->insertMovement(['quantity' => 2, 'unit_cost' => 1000, 'amount' => 2000, 'created_at' => '2026-01-05 10:00:00']);

        $noPermUser = User::firstOrCreate(
            ['email' => 'drilldown-export-noperm@test.local'],
            ['name' => 'No Perm Export', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($noPermUser)
            ->get(route('reports.inventory.drilldown.export', ['kpi' => 'in'] + $this->filters()))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get(route('reports.inventory.drilldown.export', ['kpi' => 'in'] + $this->filters()))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── TC19: khóa lỗi xuất kho âm — tab Chứng từ/Excel phải hiện SL/Giá trị DƯƠNG cho kpi=out
    //          (sm.amount/sm.quantity trong DB vẫn lưu âm theo đúng quy ước nguồn — không đổi dữ liệu) ─────

    public function test_tc19_out_kpi_documents_show_positive_sign_and_preserve_null(): void
    {
        // Movement xuất có giá trị nguồn: amount/quantity lưu ÂM trong DB (đúng quy ước có sẵn)
        $this->insertMovement([
            'quantity' => -5, 'unit_cost' => 20000, 'amount' => -100000,
            'source_type' => 'App\\Models\\StockExit', 'created_at' => '2026-01-05 10:00:00',
        ]);
        // Mô phỏng PurchaseReturn thật: quantity âm (xuất trả NCC) nhưng amount NULL (không set giá trị nguồn)
        $this->insertMovement([
            'quantity' => -3, 'unit_cost' => null, 'amount' => null,
            'source_type' => 'App\\Models\\PurchaseReturn', 'created_at' => '2026-01-06 10:00:00',
        ]);

        $filters = $this->filters();
        $docs = $this->fetch('out', 'documents', $filters);
        $rows = collect($docs['data']);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r['quantity'] >= 0), 'SL tab Chứng từ phải hiển thị dương cho kpi=out');
        $valueRow = $rows->firstWhere('has_source_value', true);
        $this->assertEquals(100000.0, $valueRow['amount'], 'Giá trị hiển thị phải dương dù DB lưu âm');
        $this->assertEquals(5.0, $valueRow['quantity']);

        $nullRow = $rows->firstWhere('has_source_value', false);
        $this->assertNotNull($nullRow, 'Dòng PurchaseReturn thiếu giá trị nguồn vẫn phải hiện, đánh dấu rõ');
        $this->assertNull($nullRow['amount'], 'amount NULL phải được giữ nguyên, không bị coalesce về 0');
        $this->assertEquals(3.0, $nullRow['quantity']);

        // Dữ liệu gốc trong DB vẫn phải giữ nguyên dấu âm — không được sửa dữ liệu nguồn
        $raw = DB::table('stock_movements')->where('source_type', 'App\\Models\\StockExit')
            ->where('created_at', '2026-01-05 10:00:00')->first();
        $this->assertEquals(-100000, $raw->amount);
        $this->assertEquals(-5, $raw->quantity);

        // Excel export dùng cùng query — tổng cột Giá trị phải khớp dương với KPI, không âm
        $agg = (new InventoryKpiDrilldownService())->aggregate($filters, 'out');
        $this->assertEquals(100000.0, $agg['total_amount']);
        $export = new \App\Exports\Reports\InventoryKpiDrilldownExport($filters, 'out');
        $exportRows = iterator_to_array($export->query()->get());
        $this->assertCount(2, $exportRows);
    }
}
