<?php

namespace Tests\Feature\Reports;

use App\Exports\Reports\InventoryTransactionReportExport;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\InventoryTransactionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TC1: opening balance chỉ tính movement trước date_from
 * TC2: mỗi dòng movement chỉ có 1 bên nhập HOẶC xuất, không bao giờ cả hai
 * TC3: dòng closing reconciles: qty_end = qty_begin + Σqty_in - Σqty_out
 * TC4: movement status=voided bị loại khỏi cả rows và opening balance
 * TC5: warehouse_id filter cô lập đúng kho (transfer 2 chân)
 * TC6: StockTransfer movement vẫn xuất hiện như 1 dòng bình thường (không bị rớt)
 * TC7: route 403 khi thiếu permission reports.view; 200 khi có
 * TC8: sản phẩm không có tồn đầu kỳ VÀ không phát sinh trong kỳ bị ẩn khỏi báo cáo;
 *      sản phẩm có tồn đầu kỳ (dù không phát sinh) vẫn phải hiện
 * TC9: dòng tổng hợp trả về đúng số 0 (không phải null/blank) khi tồn cuối = 0
 * TC10: xuất trước khi có tồn → dòng đó is_negative=true, status='was_negative' sau khi bù đủ
 * TC11: cuối kỳ vẫn âm → status='negative_ending'
 * TC12: file Excel thực tế (không chỉ mảng PHP) ghi số 0 vào cell, không bỏ trống —
 *       PhpSpreadsheet::fromArray() mặc định so sánh loose ($cellValue != null) nên số 0
 *       bị coi như null và KHÔNG được ghi vào cell nếu thiếu WithStrictNullComparison
 * TC13: chứng từ xuất có ngày CT trước ngày CT nhập nhưng ghi nhận (stock_movements
 *       .created_at) muộn hơn nhiều tháng → badge "Âm theo ngày chứng từ" (không phải
 *       "Đã từng âm kho") + backdated_note trên đúng dòng bị lùi ngày, không lan sang
 *       chứng từ bình thường
 * TC14: ngưỡng cảnh báo — đúng 3 ngày KHÔNG cảnh báo, hơn 3 ngày (3 ngày + 1 giờ) CÓ cảnh báo
 * TC15: sửa ngày chứng từ sau khi confirm (bump updated_at của stock_exits, như
 *       StockExitDateService) KHÔNG được làm thay đổi kết luận — vì backdated_note dựa
 *       vào stock_movements.created_at, không phải updated_at của chứng từ
 */
class InventoryTransactionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $wh1;
    private Warehouse $wh2;
    private Product $product;
    private InventoryTransactionReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'itr-test@test.local'],
            ['name' => 'ITR Test', 'password' => bcrypt('x'), 'is_active' => true]
        );
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');
        $this->actingAs($this->user);

        $this->wh1 = Warehouse::create(['name' => 'Kho Chính', 'code' => 'ITR-KC', 'address' => 'HN']);
        $this->wh2 = Warehouse::create(['name' => 'Kho Phụ',   'code' => 'ITR-KP', 'address' => 'HN']);

        $this->product = Product::create([
            'code' => 'ITR-SP', 'name' => 'ITR Test Product', 'unit' => 'cái',
            'cost_price' => 100000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);

        $this->service = new InventoryTransactionReportService();
    }

    private function insertMovement(array $data): void
    {
        DB::table('stock_movements')->insert(array_merge([
            'product_id' => $this->product->id, 'warehouse_id' => $this->wh1->id,
            'type' => 'in', 'quantity' => 1, 'unit_cost' => 0, 'amount' => 0,
            'status' => 'active', 'created_by' => $this->user->id, 'updated_at' => now(),
        ], $data));
    }

    private function firstGroup(array $filters): ?array
    {
        return $this->service->buildAllProductGroups($filters)
            ->firstWhere('product.code', 'ITR-SP');
    }

    public function test_tc1_opening_excludes_in_period_movements(): void
    {
        $this->insertMovement(['quantity' => 5, 'amount' => 500000, 'created_at' => '2025-12-01 10:00:00']);
        $this->insertMovement(['quantity' => 3, 'amount' => 300000, 'created_at' => '2026-01-10 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $opening = $group['rows'][0];

        $this->assertEquals('opening', $opening['row_type']);
        $this->assertEquals(5.0, $opening['qty_begin']);
        $this->assertEquals(500000.0, $opening['value_begin']);
    }

    public function test_tc2_movement_row_never_fills_both_sides(): void
    {
        $this->insertMovement(['quantity' => 5, 'amount' => 500000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => -2, 'amount' => -200000, 'created_at' => '2026-01-06 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $movementRows = array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement');

        foreach ($movementRows as $row) {
            $hasIn  = $row['qty_in']  !== null;
            $hasOut = $row['qty_out'] !== null;
            $this->assertNotEquals($hasIn, $hasOut, 'Mỗi dòng chỉ được có 1 bên nhập hoặc xuất, không cả hai');
        }
    }

    public function test_tc3_closing_row_reconciles(): void
    {
        $this->insertMovement(['quantity' => 10, 'amount' => 1000000, 'created_at' => '2025-12-01 10:00:00']);
        $this->insertMovement(['quantity' => 5,  'amount' => 500000,  'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => -3, 'amount' => -300000, 'created_at' => '2026-01-10 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $closing = end($group['rows']);

        $this->assertEquals('closing', $closing['row_type']);
        $this->assertEquals(5.0, $closing['qty_in']);
        $this->assertEquals(3.0, $closing['qty_out']);
        $this->assertEquals(12.0, $closing['qty_end'], '10 (đầu kỳ) + 5 (nhập) - 3 (xuất) = 12');
        $this->assertEquals(1200000.0, $closing['value_end']);
        $this->assertEquals(10.0, $closing['qty_begin'], 'Dòng tổng hợp phải nhắc lại tồn đầu kỳ, không để trống');
        $this->assertEquals(1000000.0, $closing['value_begin']);
    }

    public function test_tc9_closing_row_shows_zero_not_blank_when_balanced_to_zero(): void
    {
        $this->insertMovement(['quantity' => 3, 'amount' => 300000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => -3, 'amount' => -300000, 'created_at' => '2026-01-06 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $closing = end($group['rows']);

        $this->assertSame(0.0, $closing['qty_end'], 'Tồn cuối kỳ = 0 phải là số 0, không phải null/blank');
        $this->assertSame(0.0, $closing['value_end']);
        $this->assertSame(0.0, $closing['qty_begin']);
    }

    public function test_tc10_negative_stock_status_detected(): void
    {
        // Xuất trước khi có tồn (xuất 2, sau đó mới nhập 2) → từng âm, cuối kỳ về 0 → "was_negative"
        $this->insertMovement(['quantity' => -2, 'amount' => -200000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => 2,  'amount' => 200000,  'created_at' => '2026-01-10 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertEquals('was_negative', $group['status']['code']);
        $movementRows = array_values(array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement'));
        $this->assertTrue($movementRows[0]['is_negative'], 'Dòng xuất khi chưa có tồn phải được đánh dấu âm kho');
        $this->assertFalse($movementRows[1]['is_negative'], 'Sau khi nhập bù, dòng này không còn âm');
    }

    public function test_tc11_negative_ending_status_when_still_negative_at_period_end(): void
    {
        $this->insertMovement(['quantity' => -5, 'amount' => -500000, 'created_at' => '2026-01-05 10:00:00']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $closing = end($group['rows']);

        $this->assertEquals('negative_ending', $group['status']['code']);
        $this->assertEquals(-5.0, $closing['qty_end']);
        $this->assertTrue($closing['is_negative']);
    }

    public function test_tc13_backdated_exit_label_and_note(): void
    {
        // Mô phỏng case thật XK-0013: chứng từ nhập ngày 15/01, movement ghi nhận cùng
        // ngày (không bị lùi); chứng từ xuất ngày CT 10/01 (trước ngày nhập) nhưng
        // movement thực tế được ghi nhận (created_at) tận tháng 6 → "xuất trước nhập"
        // chỉ do ngày chứng từ, không phải do ghi nhận trước — xem
        // plans/260729-avco-negative-stock-cost-investigation.
        $entryId = DB::table('stock_entries')->insertGetId([
            'code' => 'NK-ITR-BD01', 'warehouse_id' => $this->wh1->id, 'entry_date' => '2026-01-15',
            'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00',
        ]);
        $this->insertMovement([
            'quantity' => 5, 'amount' => 500000, 'source_type' => 'App\\Models\\StockEntry',
            'source_id' => $entryId, 'created_at' => '2026-01-15 09:00:00',
        ]);

        $exitId = DB::table('stock_exits')->insertGetId([
            'code' => 'XK-ITR-BD01', 'warehouse_id' => $this->wh1->id, 'exit_date' => '2026-01-10',
            'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => '2026-06-20 10:00:00', 'updated_at' => '2026-06-25 14:00:00',
        ]);
        $this->insertMovement([
            'quantity' => -5, 'amount' => -500000, 'source_type' => 'App\\Models\\StockExit',
            'source_id' => $exitId, 'created_at' => '2026-06-25 14:00:00',
        ]);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertEquals('was_negative', $group['status']['code']);
        $this->assertEquals('Âm theo ngày chứng từ', $group['status']['label']);

        $movementRows = array_values(array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement'));
        $exitRow = collect($movementRows)->first(fn ($r) => $r['out_doc_code'] === 'XK-ITR-BD01');
        $entryRow = collect($movementRows)->first(fn ($r) => $r['in_doc_code'] === 'NK-ITR-BD01');

        $this->assertNotNull($exitRow['backdated_note'], 'Ghi nhận 5 tháng sau ngày CT phải có ghi chú lùi ngày');
        $this->assertStringContainsString('dấu hiệu tham khảo', $exitRow['backdated_note']);
        $this->assertNull($entryRow['backdated_note'], 'Ghi nhận cùng ngày không được gắn ghi chú lùi ngày');
    }

    public function test_tc14_backdated_note_threshold_boundary(): void
    {
        // Đúng 3 ngày (72h tròn) → KHÔNG cảnh báo.
        $exitExactId = DB::table('stock_exits')->insertGetId([
            'code' => 'XK-ITR-BD02', 'warehouse_id' => $this->wh1->id, 'exit_date' => '2026-01-10',
            'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => '2026-01-13 00:00:00', 'updated_at' => '2026-01-13 00:00:00',
        ]);
        $this->insertMovement([
            'quantity' => -1, 'amount' => -100000, 'source_type' => 'App\\Models\\StockExit',
            'source_id' => $exitExactId, 'created_at' => '2026-01-13 00:00:00',
        ]);

        // Hơn 3 ngày (72h + 1h) → CÓ cảnh báo.
        $exitOverId = DB::table('stock_exits')->insertGetId([
            'code' => 'XK-ITR-BD03', 'warehouse_id' => $this->wh1->id, 'exit_date' => '2026-01-10',
            'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => '2026-01-13 01:00:00', 'updated_at' => '2026-01-13 01:00:00',
        ]);
        $this->insertMovement([
            'quantity' => -1, 'amount' => -100000, 'source_type' => 'App\\Models\\StockExit',
            'source_id' => $exitOverId, 'created_at' => '2026-01-13 01:00:00',
        ]);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $movementRows = collect($group['rows'])->filter(fn ($r) => $r['row_type'] === 'movement');

        $exactRow = $movementRows->first(fn ($r) => $r['out_doc_code'] === 'XK-ITR-BD02');
        $overRow  = $movementRows->first(fn ($r) => $r['out_doc_code'] === 'XK-ITR-BD03');

        $this->assertNull($exactRow['backdated_note'], 'Đúng ngưỡng 3 ngày không được cảnh báo');
        $this->assertNotNull($overRow['backdated_note'], 'Vượt ngưỡng 3 ngày phải cảnh báo');
    }

    public function test_tc15_later_document_edit_does_not_change_backdated_conclusion(): void
    {
        // Xuất ghi nhận đúng ngày chứng từ (không lùi ngày).
        $exitId = DB::table('stock_exits')->insertGetId([
            'code' => 'XK-ITR-BD04', 'warehouse_id' => $this->wh1->id, 'exit_date' => '2026-01-10',
            'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => '2026-01-10 09:00:00', 'updated_at' => '2026-01-10 09:00:00',
        ]);
        $this->insertMovement([
            'quantity' => -1, 'amount' => -100000, 'source_type' => 'App\\Models\\StockExit',
            'source_id' => $exitId, 'created_at' => '2026-01-10 09:00:00',
        ]);

        // Mô phỏng admin sửa ngày xuất kho về sau (StockExitDateService) — chỉ đổi
        // exit_date/updated_at trên stock_exits, KHÔNG đổi stock_movements (đúng
        // hành vi thật của service, xem StockExitDateService.php dòng 20+59).
        DB::table('stock_exits')->where('id', $exitId)->update([
            'updated_at' => '2026-07-01 10:00:00',
        ]);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $movementRows = array_values(array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement'));
        $exitRow = collect($movementRows)->first(fn ($r) => $r['out_doc_code'] === 'XK-ITR-BD04');

        $this->assertNull(
            $exitRow['backdated_note'],
            'Sửa chứng từ sau này (bump updated_at) không được làm sai lệch kết luận vì đã dùng mốc thời gian đáng tin cậy từ stock_movements'
        );
    }

    public function test_tc12_excel_export_closing_row_zero_not_blank(): void
    {
        $this->insertMovement(['quantity' => 3, 'amount' => 300000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['quantity' => -3, 'amount' => -300000, 'created_at' => '2026-01-06 10:00:00']);

        $bytes = Excel::raw(
            new InventoryTransactionReportExport(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']),
            ExcelWriterType::XLSX
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'itr_test') . '.xlsx';
        file_put_contents($tmpFile, $bytes);
        $sheetRows = (new XlsxReader())->load($tmpFile)->getActiveSheet()->toArray(null, false, false, true);
        unlink($tmpFile);

        $closing = collect($sheetRows)->first(
            fn ($r) => ($r['A'] ?? null) === 'ITR-SP' && str_contains((string) ($r['B'] ?? ''), 'Cộng phát sinh')
        );

        $this->assertNotNull($closing, 'Không tìm thấy dòng tổng hợp trong file Excel');
        $this->assertNotNull($closing['C'], 'SL tồn đầu kỳ = 0 bị ghi thành cell trống trong file Excel thật');
        $this->assertNotNull($closing['M'], 'SL tồn sau CT = 0 bị ghi thành cell trống trong file Excel thật');
        $this->assertEquals(0.0, (float) $closing['C']);
        $this->assertEquals(0.0, (float) $closing['M']);
    }

    public function test_tc4_voided_movement_excluded(): void
    {
        $this->insertMovement(['quantity' => 5, 'amount' => 500000, 'created_at' => '2025-12-01 10:00:00']);
        $this->insertMovement(['quantity' => 9, 'amount' => 900000, 'created_at' => '2026-01-05 10:00:00', 'status' => 'voided']);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertEquals(5.0, $group['rows'][0]['qty_begin'], 'voided movement không tính vào opening');
        $movementCount = count(array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement'));
        $this->assertEquals(0, $movementCount, 'voided movement không xuất hiện trong danh sách giao dịch');
    }

    public function test_tc5_warehouse_filter_isolates_correct_warehouse(): void
    {
        $this->insertMovement(['warehouse_id' => $this->wh1->id, 'quantity' => 5, 'amount' => 500000, 'created_at' => '2026-01-05 10:00:00']);
        $this->insertMovement(['warehouse_id' => $this->wh2->id, 'quantity' => 3, 'amount' => 300000, 'created_at' => '2026-01-05 10:00:00']);

        $wh1Group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'warehouse_id' => $this->wh1->id]);
        $closing1 = end($wh1Group['rows']);
        $this->assertEquals(5.0, $closing1['qty_end']);

        $wh2Group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'warehouse_id' => $this->wh2->id]);
        $closing2 = end($wh2Group['rows']);
        $this->assertEquals(3.0, $closing2['qty_end']);
    }

    public function test_tc6_stock_transfer_movement_appears_as_normal_row(): void
    {
        $transferId = DB::table('stock_transfers')->insertGetId([
            'code' => 'CK-ITR-0001', 'from_warehouse_id' => $this->wh1->id, 'to_warehouse_id' => $this->wh2->id,
            'transfer_date' => '2026-01-08', 'status' => 'confirmed', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->insertMovement([
            'warehouse_id' => $this->wh2->id, 'quantity' => 4, 'amount' => 400000,
            'source_type' => 'App\\Models\\StockTransfer', 'source_id' => $transferId,
            'created_at' => '2026-01-08 10:00:00',
        ]);

        $group = $this->firstGroup(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $movementRows = array_values(array_filter($group['rows'], fn ($r) => $r['row_type'] === 'movement'));

        $this->assertCount(1, $movementRows);
        $this->assertEquals('CK-ITR-0001', $movementRows[0]['in_doc_code'], 'Phải join ra đúng mã CK- thật, không rớt dòng');
        $this->assertEquals(4.0, $movementRows[0]['qty_in']);
    }

    public function test_tc8_zero_activity_product_hidden_but_stock_only_product_shown(): void
    {
        // ITR-SP: có 1 movement trong kỳ → phải hiện
        $this->insertMovement(['quantity' => 2, 'amount' => 200000, 'created_at' => '2026-01-05 10:00:00']);

        // Sản phẩm hoàn toàn không có giao dịch/tồn nào → phải bị ẩn
        $emptyProduct = Product::create([
            'code' => 'ITR-EMPTY', 'name' => 'Không hoạt động', 'unit' => 'cái',
            'cost_price' => 50000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);

        // Sản phẩm có tồn đầu kỳ nhưng KHÔNG phát sinh gì trong kỳ → vẫn phải hiện
        $stockOnlyProduct = Product::create([
            'code' => 'ITR-STOCKONLY', 'name' => 'Chỉ có tồn', 'unit' => 'cái',
            'cost_price' => 80000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true,
        ]);
        DB::table('stock_movements')->insert([
            'product_id' => $stockOnlyProduct->id, 'warehouse_id' => $this->wh1->id,
            'type' => 'in', 'quantity' => 7, 'unit_cost' => 0, 'amount' => 700000,
            'status' => 'active', 'created_by' => $this->user->id,
            'created_at' => '2025-12-01 10:00:00', 'updated_at' => now(),
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $groups = $this->service->buildAllProductGroups($filters)->pluck('product.code');

        $this->assertTrue($groups->contains('ITR-SP'), 'Sản phẩm có phát sinh trong kỳ phải hiện');
        $this->assertTrue($groups->contains('ITR-STOCKONLY'), 'Sản phẩm có tồn đầu kỳ (dù không phát sinh) vẫn phải hiện');
        $this->assertFalse($groups->contains('ITR-EMPTY'), 'Sản phẩm không tồn, không phát sinh phải bị ẩn');
    }

    public function test_tc7_route_permission_gate(): void
    {
        $noPermUser = User::firstOrCreate(
            ['email' => 'itr-noperm@test.local'],
            ['name' => 'No Perm', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($noPermUser)
            ->get(route('reports.inventory_transactions'))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->get(route('reports.inventory_transactions'))
            ->assertOk();
    }
}
