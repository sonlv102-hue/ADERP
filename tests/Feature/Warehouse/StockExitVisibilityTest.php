<?php

namespace Tests\Feature\Warehouse;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Kiểm tra hành vi hiển thị tồn kho trên giao diện xuất kho:
 *
 * TC1: Sản phẩm có AVCO record qty=0 + stock_movements dương → KHÔNG hiển thị (fallback bị chặn)
 * TC2: Fallback chỉ đếm active movements, bỏ qua voided
 * TC3: Sản phẩm chưa có AVCO record → fallback đúng (active movements only)
 * TC4: Lỗi backend khi tồn kho thấp phải kèm thông tin kho khác có hàng
 */
class StockExitVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'vis-test@test.local'],
            ['name' => 'Vis Tester', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->warehouseA = Warehouse::create(['name' => 'Kho Chính', 'code' => 'KHO-CHN']);
        $this->warehouseB = Warehouse::create(['name' => 'Kho Phụ', 'code' => 'KHO-PHU']);
    }

    // TC1: Sản phẩm có AVCO qty=0 và movements dương → không hiển thị trong search
    // Đây là root cause của bug "UI hiện tồn 13 nhưng backend báo 0"
    public function test_tc1_product_with_avco_zero_and_positive_movements_excluded_from_search(): void
    {
        $product = Product::create([
            'code' => 'SP-VIS01', 'name' => 'SP AVCO zero', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);

        // AVCO record tại kho A với qty=0 (AVCO đã ghi nhận nhưng tồn = 0)
        InventoryBalance::create([
            'product_id'    => $product->id,
            'warehouse_id'  => $this->warehouseA->id,
            'qty_on_hand'   => 0,
            'value_on_hand' => 0,
            'avg_cost'      => 0,
        ]);

        // Stock movements vẫn còn dương (ví dụ: legacy movements trước khi AVCO init)
        DB::table('stock_movements')->insert([
            'warehouse_id' => $this->warehouseA->id,
            'product_id'   => $product->id,
            'quantity'     => 13,
            'type'         => 'in',
            'source_type'  => 'test',
            'source_id'    => 1,
            'status'       => 'active',
            'created_by'   => $this->user->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $res = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->warehouseA->id]));
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('value')->all();
        $this->assertNotContains(
            $product->id,
            $ids,
            'Sản phẩm có AVCO qty=0 không được hiển thị dù movements dương — tránh user thấy "Tồn: 13" nhưng backend block'
        );
    }

    // TC2: Fallback chỉ đếm active movements, bỏ qua voided
    public function test_tc2_fallback_counts_only_active_movements(): void
    {
        $product = Product::create([
            'code' => 'SP-VIS02', 'name' => 'SP Fallback Active', 'unit' => 'cái',
            'cost_price' => 50000, 'is_active' => true,
        ]);

        // Không có AVCO record → sẽ dùng fallback
        // 10 active + 5 voided → chỉ đếm 10
        DB::table('stock_movements')->insert([
            [
                'warehouse_id' => $this->warehouseA->id, 'product_id' => $product->id,
                'quantity' => 10, 'type' => 'in', 'source_type' => 'test', 'source_id' => 1,
                'status' => 'active', 'created_by' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'warehouse_id' => $this->warehouseA->id, 'product_id' => $product->id,
                'quantity' => 5, 'type' => 'in', 'source_type' => 'test', 'source_id' => 2,
                'status' => 'voided', 'created_by' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $res = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->warehouseA->id]));
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('value', $product->id);
        $this->assertNotNull($item, 'Sản phẩm phải có trong fallback (10 active movements)');
        $this->assertEquals(10.0, $item['qty'], 'Chỉ đếm 10 active, không đếm 5 voided');
    }

    // TC3: Fallback đúng cho SP chưa có AVCO (pre-AVCO init)
    public function test_tc3_fallback_works_for_products_without_avco_record(): void
    {
        $product = Product::create([
            'code' => 'SP-VIS03', 'name' => 'SP Pre-AVCO', 'unit' => 'cái',
            'cost_price' => 80000, 'is_active' => true,
        ]);

        DB::table('stock_movements')->insert([
            'warehouse_id' => $this->warehouseA->id, 'product_id' => $product->id,
            'quantity' => 7, 'type' => 'in', 'source_type' => 'test', 'source_id' => 1,
            'status' => 'active', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson(route('search.warehouse-products', ['warehouse_id' => $this->warehouseA->id]));
        $res->assertOk();

        $item = collect($res->json('data'))->firstWhere('value', $product->id);
        $this->assertNotNull($item, 'Sản phẩm chưa có AVCO phải hiển thị qua fallback');
        $this->assertEquals(7.0, $item['qty']);
    }

    // TC4: Lỗi backend khi insufficient stock phải kèm thông tin kho khác
    public function test_tc4_insufficient_stock_error_includes_other_warehouse_info(): void
    {
        foreach (['1561', '1331', '3311', '331', '6321'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }

        $product = Product::create([
            'code' => 'SP-VIS04', 'name' => 'SP Kho Phụ', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);

        // Kho Chính: AVCO qty=0 (production scenario)
        InventoryBalance::create([
            'product_id'    => $product->id,
            'warehouse_id'  => $this->warehouseA->id,
            'qty_on_hand'   => 0,
            'value_on_hand' => 0,
            'avg_cost'      => 0,
        ]);

        // Kho Phụ: AVCO qty=13 (hàng thực ở đây)
        InventoryBalance::create([
            'product_id'    => $product->id,
            'warehouse_id'  => $this->warehouseB->id,
            'qty_on_hand'   => 13,
            'value_on_hand' => 1300000,
            'avg_cost'      => 100000,
        ]);

        $res = $this->post(route('warehouse.stock-exits.store'), [
            'code'            => 'XK-VIS-TEST',
            'exit_date'       => '2026-06-15',
            'warehouse_id'    => $this->warehouseA->id,  // Kho Chính — tồn = 0
            'item_usage_type' => 'commercial',
            'issue_purpose'   => 'sale_delivery',
            'items'           => [[
                'product_id' => $product->id,
                'quantity'   => 8,  // 8 > 0 → lỗi
                'unit_price' => 0,
                'serial_ids' => [],
            ]],
        ]);

        $res->assertRedirect();
        $res->assertSessionHasErrors('items');

        $errMsg = session('errors')->first('items');
        $this->assertStringContainsString('Không đủ tồn kho', $errMsg);
        $this->assertStringContainsString('Kho Chính', $errMsg);
        $this->assertStringContainsString('Kho Phụ', $errMsg, 'Error phải gợi ý kho khác đang có hàng');
        $this->assertStringContainsString('13', $errMsg, 'Error phải nêu số lượng tại kho khác');
    }
}
