<?php

namespace Tests\Feature\Warehouse;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockEntryStatus;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductSerial;
use App\Models\PurchaseInvoice;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseInvoiceService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phiếu nhập kho thủ công (không qua đơn mua hàng).
 */
class ManualStockEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create(['email' => 'manual-nk@test.local']);
        $this->actingAs($this->user);
        Gate::before(fn ($u) => $u->email === 'manual-nk@test.local' ? true : null);

        foreach ([
            ['1331', 'Thuế GTGT', 'asset', 'debit', true],
            ['1561', 'Hàng hóa', 'asset', 'debit', true],
            ['3311', 'Phải trả NCC', 'liability', 'credit', true],
            ['711', 'Thu nhập khác', 'revenue', 'credit', true],
            ['154', 'CP SXKD dở dang', 'asset', 'debit', true],
            ['338', 'Phải trả khác', 'liability', 'credit', false],
        ] as [$code, $name, $type, $nb, $detail]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb, 'is_detail' => $detail, 'is_active' => true,
            ]);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-M', 'name' => 'Kho M']);
        $this->supplier  = Supplier::create(['code' => 'NCC-M', 'name' => 'NCC M', 'payable_account_code' => '3311']);
        $cat = ProductCategory::create(['name' => 'Cat']);
        $this->product = Product::create([
            'code' => 'SP-M', 'name' => 'Switch M', 'category_id' => $cat->id, 'cost_price' => 0, 'vat_percent' => 10,
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'kind' => 'purchase', 'code' => 'NK-M001', 'entry_date' => '2026-11-20',
            'warehouse_id' => $this->warehouse->id, 'supplier_id' => $this->supplier->id,
            'credit_account_code' => null, 'notes' => 'Mua lẻ không qua PO',
            'items' => [[
                'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 1_000_000,
                'tax_rate' => 10, 'serials' => ['SN-M1', 'SN-M2'],
            ]],
        ], $override);
    }

    private function journalLines(StockEntry $e): array
    {
        $je = JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)->firstOrFail();
        return $je->lines()->get()->map(fn ($l) => [$l->account_code, (int) $l->debit, (int) $l->credit])->all();
    }

    public function test_purchase_kind_creates_draft_and_posts_ap_on_confirm(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload())->assertSessionHasNoErrors();

        $e = StockEntry::where('code', 'NK-M001')->firstOrFail();
        $this->assertNull($e->purchase_order_id);
        $this->assertSame('3311', $e->credit_account_code);
        $this->assertTrue($e->isManualPurchase());
        $this->assertSame(StockEntryStatus::Draft, $e->status);
        $this->assertSame(2, ProductSerial::count());

        app(StockService::class)->confirmEntry($e->fresh());

        $this->assertSame(2, (int) StockMovement::where('source_id', $e->id)->sum('quantity'));
        $this->assertEquals(2, InventoryBalance::where('product_id', $this->product->id)->value('qty_on_hand'));
        $lines = $this->journalLines($e);
        $this->assertContains(['1561', 2_000_000, 0], $lines);
        $this->assertContains(['1331', 200_000, 0], $lines);
        $this->assertContains(['3311', 0, 2_200_000], $lines);
    }

    public function test_other_kind_credits_chosen_account_without_vat(): void
    {
        $p = $this->payload(['kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '711']);
        $p['items'][0]['tax_rate'] = 0;
        $this->post(route('warehouse.stock-entries.manual.store'), $p)->assertSessionHasNoErrors();

        $e = StockEntry::where('code', 'NK-M001')->firstOrFail();
        $this->assertEquals(0, $e->items()->first()->tax_rate);

        app(StockService::class)->confirmEntry($e->fresh());

        $this->assertEqualsCanonicalizing([['1561', 2_000_000, 0], ['711', 0, 2_000_000]], $this->journalLines($e));
    }

    public function test_validation_rules(): void
    {
        $url = route('warehouse.stock-entries.manual.store');
        $this->post($url, $this->payload(['supplier_id' => null]))->assertSessionHasErrors('supplier_id');
        $this->post($url, $this->payload(['kind' => 'other', 'credit_account_code' => null]))->assertSessionHasErrors('credit_account_code');
        $this->post($url, $this->payload(['kind' => 'other', 'credit_account_code' => '338']))->assertSessionHasErrors('credit_account_code'); // TK tổng hợp
        $this->post($url, $this->payload(['kind' => 'other', 'credit_account_code' => '3311']))->assertSessionHasErrors('credit_account_code');
        $this->post($url, $this->payload(['kind' => 'other', 'credit_account_code' => '1561']))->assertSessionHasErrors('credit_account_code');
        $this->post($url, $this->payload(['items' => []]))->assertSessionHasErrors('items');

        ProductSerial::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-M1', 'status' => 'in_stock']);
        $this->post($url, $this->payload())->assertSessionHasErrors('items.0.serials');
        $this->assertSame(0, StockEntry::count());
    }

    public function test_update_draft_and_edit_redirect(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload());
        $e = StockEntry::firstOrFail();

        $this->get(route('warehouse.stock-entries.edit', $e))->assertRedirect(route('warehouse.stock-entries.manual.edit', $e));
        $this->get(route('warehouse.stock-entries.manual.edit', $e))->assertOk();

        $this->put(route('warehouse.stock-entries.manual.update', $e), $this->payload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 500_000, 'tax_rate' => 8,
                         'serials' => ['SN-M1']]],
        ]))->assertSessionHasNoErrors();

        $item = $e->fresh()->items()->firstOrFail();
        $this->assertSame(3, (int) $item->quantity);
        $this->assertSame(['SN-M1'], ProductSerial::pluck('serial_number')->all());
    }

    public function test_confirmed_entry_cannot_be_edited_and_cancel_reverses(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload(['items' => [[
            'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100_000, 'tax_rate' => 10, 'serials' => [],
        ]]]));
        $e = StockEntry::firstOrFail();
        $svc = app(StockService::class);
        $svc->confirmEntry($e->fresh());

        $this->put(route('warehouse.stock-entries.manual.update', $e), $this->payload())->assertRedirect();
        $this->assertSame(1, (int) $e->fresh()->items()->value('quantity'));

        $svc->cancelEntry($e->fresh());
        $this->assertSame(0, (int) StockMovement::where('product_id', $this->product->id)->sum('quantity'));
    }

    public function test_inventory_backed_invoice_does_not_double_post_ap(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload());
        app(StockService::class)->confirmEntry(StockEntry::firstOrFail());

        // purchase_invoices.purchase_order_id chỉ nullable trên PostgreSQL (mig 900113) → dựng model
        // không lưu và gọi thẳng hàm hạch toán mà transition(Valid) sẽ gọi.
        $inv = new PurchaseInvoice([
            'code' => 'MH-HD-M1', 'supplier_id' => $this->supplier->id, 'purchase_order_id' => null,
            'invoice_date' => '2026-11-20', 'subtotal' => 2_000_000, 'tax_amount' => 200_000, 'total' => 2_200_000,
            'invoice_type' => 'resale_goods',
        ]);
        $inv->id = 987654;
        $inv->setRelation('items', collect());
        $inv->setRelation('supplier', $this->supplier);

        $svc = app(PurchaseInvoiceService::class);
        (fn () => $this->postInvoiceEntryIfNeeded($inv))->call($svc);

        $this->assertFalse(JournalEntry::where('reference_type', 'purchase_invoice')->exists());

        // Đối chứng: cùng hóa đơn nhưng loại dịch vụ thì CÓ ghi 331 → cảnh báo trên form là cần thiết
        $inv->invoice_type = 'external_service';
        (fn () => $this->postInvoiceEntryIfNeeded($inv))->call($svc);
        $this->assertTrue(JournalEntry::where('reference_type', 'purchase_invoice')->exists());
    }

    public function test_requires_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('warehouse.stock-entries.manual.create'))->assertForbidden();
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload())->assertForbidden();
    }

    public function test_create_page_renders(): void
    {
        $this->get(route('warehouse.stock-entries.manual.create'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Warehouse/StockEntries/ManualForm')->has('warehouses', 1));
    }

    private function projectWithWip(int $wip): Project
    {
        $customer = Customer::create(['code' => 'KH-M', 'name' => 'KH M']);
        $p = Project::create(['code' => 'DA-M', 'name' => 'DA M', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $this->user->id]);
        ProjectWipEntry::create(['project_id' => $p->id, 'source_type' => 'manual', 'source_id' => 0,
            'cost_type' => 'material', 'amount' => $wip, 'description' => 'Xuất vật tư', 'entry_date' => '2026-11-01',
            'status' => 'active', 'created_by' => $this->user->id]);
        return $p;
    }

    private function returnPayload(Project $p, int $qty = 2): array
    {
        return $this->payload(['kind' => 'project_return', 'supplier_id' => $this->supplier->id, 'project_id' => $p->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => 1_000_000,
                         'tax_rate' => 10, 'serials' => []]]]);
    }

    public function test_project_return_credits_154_and_reduces_wip(): void
    {
        $p = $this->projectWithWip(5_000_000);
        $this->post(route('warehouse.stock-entries.manual.store'), $this->returnPayload($p))->assertSessionHasNoErrors();

        $e = StockEntry::firstOrFail();
        $this->assertSame('project_return', $e->manualKind());
        $this->assertSame($p->id, $e->project_id);
        $this->assertNull($e->supplier_id);                          // dự án trả về: không gắn NCC
        $this->assertEquals(0, $e->items()->value('tax_rate'));     // không VAT

        app(StockService::class)->confirmEntry($e->fresh());

        $je = JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)->firstOrFail();
        $lines = $je->lines()->get();
        $this->assertEqualsCanonicalizing([['1561', 2_000_000, 0], ['154', 0, 2_000_000]],
            $lines->map(fn ($l) => [$l->account_code, (int) $l->debit, (int) $l->credit])->all());
        $this->assertSame($p->id, (int) $lines->firstWhere('account_code', '154')->project_id);

        $this->assertSame(3_000_000, (int) ProjectWipEntry::where('project_id', $p->id)->sum('amount'));
        // Hàng trả về là tồn kho chung (AVCO), không phải lô dự án
        $this->assertEquals(2, InventoryBalance::where('product_id', $this->product->id)->value('qty_on_hand'));
        $this->assertNull($e->items()->value('project_id'));

        app(StockService::class)->cancelEntry($e->fresh());
        $this->assertSame(5_000_000, (int) ProjectWipEntry::where('project_id', $p->id)->sum('amount'));
    }

    public function test_project_return_cannot_exceed_project_wip(): void
    {
        $p = $this->projectWithWip(1_500_000);
        $this->post(route('warehouse.stock-entries.manual.store'), $this->returnPayload($p))->assertSessionHasErrors('project_id');
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload(['kind' => 'project_return']))
            ->assertSessionHasErrors('project_id');
        $this->assertSame(0, StockEntry::count());
    }

    public function test_other_kind_allows_154_without_project(): void
    {
        $p = $this->projectWithWip(5_000_000);
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload([
            'kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '154',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 1_000_000,
                         'tax_rate' => 0, 'serials' => []]],
        ]))->assertSessionHasNoErrors();

        $e = StockEntry::firstOrFail();
        $this->assertSame('other', $e->manualKind());
        $this->assertNull($e->project_id);
        app(StockService::class)->confirmEntry($e->fresh());

        $lines = JournalEntry::where('reference_type', 'stock_entry')->where('reference_id', $e->id)
            ->firstOrFail()->lines()->get();
        $this->assertEqualsCanonicalizing([['1561', 2_000_000, 0], ['154', 0, 2_000_000]],
            $lines->map(fn ($l) => [$l->account_code, (int) $l->debit, (int) $l->credit])->all());
        $this->assertNull($lines->firstWhere('account_code', '154')->project_id);
        // Không tự phân bổ/ghi giảm WIP của dự án nào
        $this->assertSame(5_000_000, (int) ProjectWipEntry::sum('amount'));
        $this->assertEquals(2, InventoryBalance::where('product_id', $this->product->id)->value('qty_on_hand'));
        $this->assertEquals(2_000_000, (float) InventoryBalance::where('product_id', $this->product->id)->value('value_on_hand'));

        app(StockService::class)->cancelEntry($e->fresh());
        $this->assertSame(5_000_000, (int) ProjectWipEntry::sum('amount'));
        $this->assertEquals(0, InventoryBalance::where('product_id', $this->product->id)->value('qty_on_hand'));
    }

    public function test_other_kind_still_blocks_inventory_accounts(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload([
            'kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '1561',
        ]))->assertSessionHasErrors('credit_account_code');
    }

    public function test_other_kind_supplier_is_optional(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload([
            'kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '711',
        ]))->assertSessionHasNoErrors();
        $this->assertNull(StockEntry::firstOrFail()->supplier_id);
    }

    public function test_other_kind_with_vat_rate_posts_1331(): void
    {
        $this->post(route('warehouse.stock-entries.manual.store'), $this->payload([
            'kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '711',
        ]))->assertSessionHasNoErrors();

        $e = StockEntry::where('code', 'NK-M001')->firstOrFail();
        $this->assertEquals(10, $e->items()->first()->tax_rate);
        app(StockService::class)->confirmEntry($e->fresh());

        $this->assertEqualsCanonicalizing(
            [['1561', 2_000_000, 0], ['1331', 200_000, 0], ['711', 0, 2_200_000]], $this->journalLines($e));
    }

    public function test_manual_tax_amount_overrides_rate(): void
    {
        $p = $this->payload(['kind' => 'other', 'supplier_id' => null, 'credit_account_code' => '711']);
        $p['items'][0]['tax_amount'] = 199_999;
        $this->post(route('warehouse.stock-entries.manual.store'), $p)->assertSessionHasNoErrors();

        $e = StockEntry::where('code', 'NK-M001')->firstOrFail();
        app(StockService::class)->confirmEntry($e->fresh());

        $this->assertEqualsCanonicalizing(
            [['1561', 2_000_000, 0], ['1331', 199_999, 0], ['711', 0, 2_199_999]], $this->journalLines($e));
        $show = $this->get(route('warehouse.stock-entries.show', $e))->viewData('page')['props']['entry']['items'][0];
        $this->assertSame(199_999, $show['tax_amount']);
    }

    public function test_tax_amount_cannot_exceed_line_value(): void
    {
        $p = $this->payload();
        $p['items'][0]['tax_amount'] = 2_000_001;
        $this->post(route('warehouse.stock-entries.manual.store'), $p)->assertSessionHasErrors('items.0.tax_amount');
        $this->assertSame(0, StockEntry::count());
    }

    public function test_project_return_ignores_vat_input(): void
    {
        $proj = $this->projectWithWip(5_000_000);
        $p = $this->returnPayload($proj);
        $p['items'][0]['tax_rate'] = 10;
        $p['items'][0]['tax_amount'] = 50_000;
        $this->post(route('warehouse.stock-entries.manual.store'), $p)->assertSessionHasNoErrors();

        $item = StockEntry::firstOrFail()->items()->first();
        $this->assertEquals(0, $item->tax_rate);
        $this->assertNull($item->tax_amount);
    }
}
