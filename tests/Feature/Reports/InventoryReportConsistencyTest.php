<?php

namespace Tests\Feature\Reports;

use App\Models\AccountingPeriod;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\InventoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test báo cáo tồn kho:
 * TC1: UI và Excel dùng cùng giá trị (không dùng cost_price × qty)
 * TC2: Không dùng inventory_balances để tính tồn đầu kỳ
 * TC3: Group theo kho đúng (filter warehouse)
 * TC4: Date range — opening chỉ tính movement trước kỳ
 * TC5: CAP 1.4 regression — cost_price khác vs amount thực tế
 */
class InventoryReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User      $user;
    private Warehouse $wh1;
    private Warehouse $wh2;
    private Product   $product;
    private InventoryReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'report.test@test.local'],
            ['name' => 'Report Test', 'password' => bcrypt('x'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->wh1 = Warehouse::create(['name' => 'Kho Chính', 'code' => 'KC', 'address' => 'HN']);
        $this->wh2 = Warehouse::create(['name' => 'Kho Phụ',   'code' => 'KP', 'address' => 'HN']);

        $this->product = Product::create([
            'code'        => 'CAP-TEST',
            'name'        => 'CAP Test Product',
            'unit'        => 'cái',
            'cost_price'  => 200000,   // cost_price hiện tại — KHÔNG dùng để tính báo cáo
            'vat_percent' => 10,
            'item_type'   => 'product',
            'is_active'   => true,
        ]);

        $this->service = new InventoryReportService();
    }

    /**
     * Insert movement trực tiếp qua DB để set ngày tùy ý (không bị Eloquent override)
     */
    private function insertMovement(array $data): void
    {
        DB::table('stock_movements')->insert(array_merge([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->wh1->id,
            'type'         => 'in',
            'quantity'     => 1,
            'unit_cost'    => 0,
            'amount'       => 0,
            'status'       => 'active',
            'created_by'   => $this->user->id,
            'updated_at'   => now(),
        ], $data));
    }

    // ── TC1: UI và Excel dùng cùng giá trị (value_begin từ sm.amount, không phải cost_price) ──

    public function test_tc1_ui_and_excel_use_same_opening_value(): void
    {
        // Nhập 2 units với giá thực tế 759,259.5 mỗi cái → tổng 1,518,519
        // (cost_price hiện tại = 200,000 → nếu dùng cost_price: 2 × 200,000 = 400,000 → SAI)
        $this->insertMovement([
            'quantity'   => 2,
            'unit_cost'  => 759259.5,
            'amount'     => 1518519.0,
            'created_at' => '2025-12-15 10:00:00',  // trước kỳ báo cáo 2026
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];

        $rows = $this->service->buildAllRows($filters);
        $row  = $rows->firstWhere('code', 'CAP-TEST');

        $this->assertNotNull($row, 'Service phải trả về row cho sản phẩm CAP-TEST');

        // Kiểm tra: value_begin phải là SUM(sm.amount) = 1,518,519
        $this->assertEquals(2.0,        $row['stock_begin'],  'Opening qty phải là 2');
        $this->assertEquals(1518519.0,  $row['value_begin'],
            'Opening value phải là 1,518,519 (từ sm.amount) — không phải 400,000 (2 × cost_price)');

        // Kiểm tra: Excel và UI dùng cùng service → cùng kết quả
        $excelRows = $this->service->buildAllRows($filters);
        $excelRow  = $excelRows->firstWhere('code', 'CAP-TEST');
        $this->assertEquals($row['value_begin'], $excelRow['value_begin'],
            'UI và Excel phải cho cùng value_begin');
    }

    // ── TC2: Không dùng inventory_balances làm tồn đầu kỳ ──────────────────────────

    public function test_tc2_does_not_use_inventory_balances_for_opening(): void
    {
        // inventory_balance hiện tại = 10 qty (tồn hiện tại, không phải tồn đầu kỳ)
        InventoryBalance::create([
            'product_id'    => $this->product->id,
            'warehouse_id'  => $this->wh1->id,
            'qty_on_hand'   => 10,
            'value_on_hand' => 2000000,
            'avg_cost'      => 200000,
        ]);

        // Nhưng tồn đầu kỳ thực tế chỉ = 3 (theo stock_movements)
        $this->insertMovement([
            'quantity'   => 3,
            'unit_cost'  => 300000,
            'amount'     => 900000,
            'created_at' => '2025-12-20 10:00:00',  // trước kỳ
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $rows    = $this->service->buildAllRows($filters);
        $row     = $rows->firstWhere('code', 'CAP-TEST');

        $this->assertNotNull($row);
        // Tồn đầu kỳ phải là 3 (từ movements), không phải 10 (từ inventory_balances)
        $this->assertEquals(3.0,      $row['stock_begin'],  'opening qty phải từ movements, không từ inventory_balances');
        $this->assertEquals(900000.0, $row['value_begin'],  'opening value phải từ sm.amount, không từ inventory_balances');
    }

    // ── TC3: Group theo kho — filter warehouse cô lập đúng kho ──────────────────────

    public function test_tc3_warehouse_filter_isolates_correct_warehouse(): void
    {
        // wh1: 5 units, giá trị 500,000
        DB::table('stock_movements')->insert([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->wh1->id,
            'type'         => 'in',
            'quantity'     => 5,
            'unit_cost'    => 100000,
            'amount'       => 500000,
            'status'       => 'active',
            'created_by'   => $this->user->id,
            'created_at'   => '2025-12-01 10:00:00',
            'updated_at'   => now(),
        ]);
        // wh2: 3 units, giá trị 360,000
        DB::table('stock_movements')->insert([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->wh2->id,
            'type'         => 'in',
            'quantity'     => 3,
            'unit_cost'    => 120000,
            'amount'       => 360000,
            'status'       => 'active',
            'created_by'   => $this->user->id,
            'created_at'   => '2025-12-01 10:00:00',
            'updated_at'   => now(),
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];

        // Báo cáo tổng: cộng cả 2 kho
        $totalRow = $this->service->buildAllRows($filters)->firstWhere('code', 'CAP-TEST');
        $this->assertEquals(8.0,      $totalRow['stock_begin'],  'Tổng opening qty = 5 + 3 = 8');
        $this->assertEquals(860000.0, $totalRow['value_begin'],  'Tổng opening value = 500,000 + 360,000 = 860,000');

        // Báo cáo filter theo wh1
        $wh1Row = $this->service->buildAllRows(array_merge($filters, ['warehouse_id' => $this->wh1->id]))->firstWhere('code', 'CAP-TEST');
        $this->assertEquals(5.0,      $wh1Row['stock_begin'],  'wh1 opening qty = 5');
        $this->assertEquals(500000.0, $wh1Row['value_begin'],  'wh1 opening value = 500,000');

        // Báo cáo filter theo wh2
        $wh2Row = $this->service->buildAllRows(array_merge($filters, ['warehouse_id' => $this->wh2->id]))->firstWhere('code', 'CAP-TEST');
        $this->assertEquals(3.0,      $wh2Row['stock_begin'],  'wh2 opening qty = 3');
        $this->assertEquals(360000.0, $wh2Row['value_begin'],  'wh2 opening value = 360,000');
    }

    // ── TC4: Date range — opening chỉ tính movement trước kỳ ────────────────────────

    public function test_tc4_date_range_opening_excludes_in_period_and_after(): void
    {
        // Movement trước kỳ: ảnh hưởng opening
        $this->insertMovement([
            'quantity'   => 4,
            'unit_cost'  => 100000,
            'amount'     => 400000,
            'created_at' => '2025-12-31 23:59:59',  // trước 2026-01-01
        ]);
        // Movement trong kỳ: ảnh hưởng stock_in
        $this->insertMovement([
            'quantity'   => 6,
            'unit_cost'  => 150000,
            'amount'     => 900000,
            'created_at' => '2026-03-15 10:00:00',
        ]);
        // Movement sau kỳ: không ảnh hưởng gì
        $this->insertMovement([
            'quantity'   => 2,
            'unit_cost'  => 200000,
            'amount'     => 400000,
            'created_at' => '2027-01-05 10:00:00',
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $row     = $this->service->buildAllRows($filters)->firstWhere('code', 'CAP-TEST');

        $this->assertNotNull($row);
        $this->assertEquals(4.0,      $row['stock_begin'],  'Opening chỉ tính movement trước 2026-01-01');
        $this->assertEquals(400000.0, $row['value_begin'],  'Opening value phải là 400,000 (trước kỳ)');
        $this->assertEquals(6.0,      $row['stock_in'],     'Nhập trong kỳ chỉ gồm movement trong kỳ');
        $this->assertEquals(900000.0, $row['value_in'],     'Value nhập trong kỳ = 900,000');
        $this->assertEquals(10.0,     $row['stock_end'],    'Closing = 4 + 6 = 10 (không cộng movement sau kỳ)');
    }

    // ── TC5: CAP 1.4 regression — value_begin từ sm.amount ≠ qty × cost_price ────────

    public function test_tc5_cap14_regression_value_begin_not_cost_price_times_qty(): void
    {
        // Mô phỏng CAP 1.4: qty=2, amount thực = 1,518,519; cost_price hiện tại = 200,000
        $this->insertMovement([
            'quantity'   => 2,
            'unit_cost'  => 759259.5,
            'amount'     => 1518519.0,
            'created_at' => '2025-11-01 10:00:00',
        ]);

        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $row     = $this->service->buildAllRows($filters)->firstWhere('code', 'CAP-TEST');

        $this->assertNotNull($row);

        // value_begin phải từ sm.amount, không từ cost_price × qty
        $costPriceCalc = $row['stock_begin'] * $row['cost_price']; // 2 × 200,000 = 400,000
        $this->assertEquals(1518519.0, $row['value_begin'],
            'value_begin phải là SUM(sm.amount) = 1,518,519');
        $this->assertNotEquals($costPriceCalc, $row['value_begin'],
            'value_begin không được là cost_price × qty (cách tính sai của Excel cũ)');

        // UI route cũng phải trả về cùng giá trị
        $resp = $this->get(route('reports.inventory', [
            'date_from' => '2026-01-01',
            'date_to'   => '2026-12-31',
        ]));
        $resp->assertOk();

        $uiRows = $resp->viewData('page')['props']['rows']['data'] ?? [];
        $uiRow  = collect($uiRows)->firstWhere('code', 'CAP-TEST');
        $this->assertNotNull($uiRow, 'UI phải trả về row CAP-TEST');
        $this->assertEquals(1518519.0, $uiRow['value_begin'],
            'UI value_begin phải khớp với service: 1,518,519');
    }
}
