<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class PurchaseOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;
    private Product $product;
    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        foreach (['purchasing.view', 'purchasing.create'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p]);
        }
        $this->user->givePermissionTo(['purchasing.view', 'purchasing.create']);
        $this->actingAs($this->user);

        $this->supplier = Supplier::create(['code' => 'NCC-0001', 'name' => 'Nhà Cung Cấp A', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['name' => 'Kho chính', 'address' => 'HN', 'manager_id' => $this->user->id, 'is_active' => true]);
        $this->product  = Product::create(['code' => 'SP-0001', 'name' => 'Sản phẩm A', 'unit' => 'cái', 'cost_price' => 1000000, 'vat_percent' => 10, 'item_type' => 'product', 'is_active' => true]);
        $this->product2 = Product::create(['code' => 'SP-0002', 'name' => 'Sản phẩm B', 'unit' => 'cái', 'cost_price' => 2000000, 'vat_percent' => 8,  'item_type' => 'product', 'is_active' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeExcel(array $rows): UploadedFile
    {
        // Build CSV content (simpler than real xlsx for tests)
        $headers = ['order_code', 'order_date', 'expected_date', 'supplier_code', 'warehouse', 'product_code', 'quantity', 'unit_price', 'vat_rate', 'subtotal', 'tax_amount', 'total', 'notes'];
        $lines   = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => $v ?? '', $row));
        }
        $csv = implode("\n", $lines);

        $tmp = tempnam(sys_get_temp_dir(), 'po_import_') . '.csv';
        file_put_contents($tmp, $csv);

        return new UploadedFile($tmp, 'import.csv', 'text/csv', null, true);
    }

    private function previewWithRows(array $rows)
    {
        $file = $this->makeExcel($rows);
        return $this->post(route('purchasing.purchase-orders.import.preview'), ['file' => $file]);
    }

    private function confirmWith(string $action = 'skip')
    {
        return $this->post(route('purchasing.purchase-orders.import.confirm'), ['duplicate_action' => $action]);
    }

    // ── Template download ─────────────────────────────────────────────────────

    public function test_template_download_returns_excel(): void
    {
        Excel::fake();
        $response = $this->get(route('purchasing.purchase-orders.import.template'));
        $response->assertStatus(200);
        Excel::assertDownloaded('purchase-order-template.xlsx');
    }

    // ── Preview (no DB writes) ────────────────────────────────────────────────

    public function test_preview_single_order_single_item(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 2, 5000000, 10, 10000000, 1000000, 11000000, ''],
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn ($p) => $p
            ->component('Purchasing/PurchaseOrders/Index')
            ->where('preview.valid_orders', 1)
            ->where('preview.error_count', 0)
            ->where('preview.total_rows', 1)
        );

        // Preview must NOT write to DB
        $this->assertDatabaseMissing('purchase_orders', ['code' => 'MH-001']);
    }

    public function test_preview_single_order_multiple_items(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 10000000, 10, 10000000, 1000000, 11000000, ''],
            ['MH-001', '',           '', '',          '',           'SP-0002', 2,  5000000,  8, 10000000,  800000, 10800000, ''],
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn ($p) => $p
            ->where('preview.valid_orders', 1)
            ->where('preview.error_count', 0)
            ->where('preview.total_rows', 2)
            ->where('preview.orders.0.items', fn ($items) => count($items) === 2)
        );
        $this->assertDatabaseMissing('purchase_orders', ['code' => 'MH-001']);
    }

    public function test_preview_multiple_orders_in_one_file(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 5000000, 10, '', '', '', ''],
            ['MH-002', '2026-06-02', '', 'NCC-0001', 'Kho chính', 'SP-0002', 3, 2000000,  8, '', '', '', ''],
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn ($p) => $p->where('preview.valid_orders', 2));
        $this->assertEquals(0, PurchaseOrder::count());
    }

    public function test_preview_detects_amount_mismatch_warning(): void
    {
        // Use ASCII warehouse name for CSV encoding safety
        Warehouse::create(['name' => 'Kho chinh', 'address' => 'HN', 'manager_id' => $this->user->id, 'is_active' => true]);

        // Excel says total = 9000000 but computed total = 11000000 (10M + 10% VAT) → diff=2M → warning
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chinh', 'SP-0001', 1, 10000000, 10, 10000000, 1000000, 9000000, ''],
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn ($p) => $p->where('preview.warning_count', fn ($v) => $v >= 1));
    }

    public function test_preview_missing_required_column_returns_error(): void
    {
        // supplier_code missing
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', '', 'Kho chính', 'SP-0001', 1, 5000000, 10, '', '', '', ''],
        ]);

        $response->assertStatus(200);
        $response->assertInertia(fn ($p) => $p
            ->where('preview.valid_orders', 0)
            ->where('preview.error_count', 1)
        );
    }

    public function test_preview_invalid_supplier_returns_error(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-WRONG', 'Kho chính', 'SP-0001', 1, 5000000, 10, '', '', '', ''],
        ]);

        $response->assertInertia(fn ($p) => $p->where('preview.error_count', 1));
    }

    public function test_preview_invalid_warehouse_returns_error(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho Sai', 'SP-0001', 1, 5000000, 10, '', '', '', ''],
        ]);

        $response->assertInertia(fn ($p) => $p->where('preview.error_count', 1));
    }

    public function test_preview_invalid_product_returns_error(): void
    {
        $response = $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-WRONG', 1, 5000000, 10, '', '', '', ''],
        ]);

        $response->assertInertia(fn ($p) => $p->where('preview.error_count', 1));
    }

    public function test_preview_detects_existing_order_code(): void
    {
        PurchaseOrder::create([
            'code' => 'MH-EXIST', 'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $response = $this->previewWithRows([
            ['MH-EXIST', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 5000000, 0, '', '', '', ''],
        ]);

        $response->assertInertia(fn ($p) => $p
            ->where('preview.has_duplicates', true)
            ->where('preview.orders.0.exists_in_db', true)
        );
    }

    // ── VAT calculations ──────────────────────────────────────────────────────

    public function test_preview_vat_zero_percent(): void
    {
        $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 2, 5000000, 0, '', '', '', ''],
        ]);
        $this->confirmWith('skip');

        $item = PurchaseOrderItem::first();
        $this->assertEquals(0, (float)$item->vat_rate);
    }

    public function test_preview_vat_8_percent(): void
    {
        $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 5000000, 8, '', '', '', ''],
        ]);
        $this->confirmWith('skip');

        $item = PurchaseOrderItem::first();
        $this->assertEquals(8, (float)$item->vat_rate);
    }

    public function test_preview_vat_10_percent(): void
    {
        $this->previewWithRows([
            ['MH-001', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 10000000, 10, '', '', '', ''],
        ]);
        $this->confirmWith('skip');

        $item = PurchaseOrderItem::first();
        $this->assertEquals(10, (float)$item->vat_rate);
    }

    // ── Confirm saves to DB ───────────────────────────────────────────────────

    public function test_confirm_creates_orders_in_database(): void
    {
        $this->previewWithRows([
            ['MH-NEW', '2026-06-01', '2026-06-15', 'NCC-0001', 'Kho chính', 'SP-0001', 2, 5000000, 10, '', '', '', 'Ghi chú'],
        ]);

        $response = $this->confirmWith('skip');
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_orders', ['code' => 'MH-NEW', 'notes' => 'Ghi chú']);
        $this->assertDatabaseHas('purchase_order_items', ['quantity' => 2, 'unit_price' => '5000000.00', 'vat_rate' => '10.00']);
    }

    public function test_confirm_skips_duplicate_when_action_is_skip(): void
    {
        $existing = PurchaseOrder::create([
            'code' => 'MH-EXIST', 'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->previewWithRows([
            ['MH-EXIST', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 5000000, 0, '', '', '', ''],
        ]);
        $this->confirmWith('skip');

        // Items should NOT be added to the existing order
        $this->assertEquals(0, PurchaseOrderItem::where('purchase_order_id', $existing->id)->count());
    }

    public function test_confirm_aborts_when_action_is_abort_and_duplicate_exists(): void
    {
        PurchaseOrder::create([
            'code' => 'MH-EXIST', 'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id, 'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $this->previewWithRows([
            ['MH-EXIST', '2026-06-01', '', 'NCC-0001', 'Kho chính', 'SP-0001', 1, 5000000, 0, '', '', '', ''],
        ]);
        $response = $this->confirmWith('abort');

        $response->assertSessionHas('error');
        $this->assertEquals(0, PurchaseOrderItem::count());
    }

    public function test_confirm_without_preview_returns_error(): void
    {
        // No preview in session
        $response = $this->confirmWith('skip');
        $response->assertSessionHas('error');
    }
}
