<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockEntryVatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        foreach (['warehouse.view', 'warehouse.create'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p]);
        }
        $this->user->givePermissionTo(['warehouse.view', 'warehouse.create']);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create(['name' => 'Kho chính', 'address' => 'HN', 'manager_id' => $this->user->id, 'is_active' => true]);

        // Account codes required by postEntryJournal FK constraint
        // postEntryJournal uses '1561' (detail) not '156' (parent) per TT133
        foreach ([
            ['code' => '156',  'name' => 'Hàng hoá',           'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => false, 'parent_code' => null],
            ['code' => '1561', 'name' => 'Hàng hoá kho',        'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => '156'],
            ['code' => '1331', 'name' => 'Thuế GTGT đầu vào',  'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'parent_code' => null],
            ['code' => '331',  'name' => 'Phải trả NCC',        'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => false, 'parent_code' => null],
            ['code' => '3311', 'name' => 'NCC trong nước',      'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true,  'parent_code' => '331'],
        ] as $acc) {
            \App\Models\AccountCode::updateOrCreate(['code' => $acc['code']], array_merge($acc, ['level' => 3, 'is_active' => true]));
        }

        // Supplier cần payable_account_code để postEntryJournal dùng TK chi tiết
        $this->supplier = Supplier::create([
            'code' => 'NCC-001', 'name' => 'NCC Test',
            'is_active' => true, 'payable_account_code' => '3311',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makePo(array $items): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TST-' . rand(1000, 9999),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => now()->toDateString(),
            'status'       => PurchaseOrderStatus::Sent,
            'created_by'   => $this->user->id,
        ]);

        foreach ($items as $i) {
            $product = Product::create([
                'code'       => 'SP-' . rand(1000, 9999),
                'name'       => $i['name'] ?? 'Sản phẩm test',
                'unit'       => 'cái',
                'cost_price' => $i['unit_price'],
                'vat_percent'=> $i['vat_rate'],
                'item_type'  => 'product',
                'is_active'  => true,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id'        => $product->id,
                'quantity'          => $i['qty'],
                'unit_price'        => $i['unit_price'],
                'vat_rate'          => $i['vat_rate'],
            ]);
        }

        return $po->load('items.product');
    }

    private function createEntry(PurchaseOrder $po, array $itemOverrides = []): StockEntry
    {
        $items = $po->items->map(fn ($i) => array_merge([
            'product_id' => $i->product_id,
            'quantity'   => $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'tax_rate'   => (float) $i->vat_rate,
            'serials'    => [],
        ], $itemOverrides[$i->product_id] ?? []))->values()->toArray();

        $response = $this->post(route('warehouse.stock-entries.store'), [
            'purchase_order_id' => $po->id,
            'code'              => 'NK-TST-' . rand(1000, 9999),
            'entry_date'        => now()->toDateString(),
            'notes'             => '',
            'items'             => $items,
        ]);

        $response->assertRedirect();
        return StockEntry::latest()->first();
    }

    // ── Show page: subtotal_excl / tax_amount / total ─────────────────────────

    public function test_show_vat_zero_percent(): void
    {
        $po    = $this->makePo([['name' => 'SP A', 'unit_price' => 5000000, 'vat_rate' => 0, 'qty' => 2]]);
        $entry = $this->createEntry($po);

        $response = $this->get(route('warehouse.stock-entries.show', $entry->id));
        $response->assertStatus(200);

        $items = $response->original->getData()['page']['props']['entry']['items'];
        $item  = $items[0];

        $this->assertEquals(10000000, $item['subtotal_excl']);
        $this->assertEquals(0,        $item['tax_amount']);
        $this->assertEquals(10000000, $item['total']);
    }

    public function test_show_vat_8_percent(): void
    {
        // unit_price EXCL VAT = 5000000, qty = 2, VAT 8%
        // subtotal_excl = 10_000_000, tax = 800_000, total = 10_800_000
        $po    = $this->makePo([['name' => 'SP B', 'unit_price' => 5000000, 'vat_rate' => 8, 'qty' => 2]]);
        $entry = $this->createEntry($po);

        $response = $this->get(route('warehouse.stock-entries.show', $entry->id));
        $items = $response->original->getData()['page']['props']['entry']['items'];
        $item  = $items[0];

        $this->assertEquals(10000000, $item['subtotal_excl']);
        $this->assertEquals(800000,   $item['tax_amount']);
        $this->assertEquals(10800000, $item['total']);
    }

    public function test_show_vat_10_percent(): void
    {
        // unit_price = 10_000_000, qty = 1, VAT 10%
        // subtotal_excl = 10_000_000, tax = 1_000_000, total = 11_000_000
        $po    = $this->makePo([['name' => 'SP C', 'unit_price' => 10000000, 'vat_rate' => 10, 'qty' => 1]]);
        $entry = $this->createEntry($po);

        $response = $this->get(route('warehouse.stock-entries.show', $entry->id));
        $items = $response->original->getData()['page']['props']['entry']['items'];
        $item  = $items[0];

        $this->assertEquals(10000000, $item['subtotal_excl']);
        $this->assertEquals(1000000,  $item['tax_amount']);
        $this->assertEquals(11000000, $item['total']);
    }

    public function test_show_total_equals_mh002_scenario(): void
    {
        // Reproduce MH-002 bug:
        // HP: unit_price=2_231_482, qty=2, VAT 8%  → subtotal=4_462_964, tax=357_037, total=4_820_001
        // BLĐ: unit_price=7_481_481, qty=1, VAT 8% → subtotal=7_481_481, tax=598_518,  total=8_080_000 (rounded)
        // Grand total = ~12_900_001 ≈ 12_900_000
        $po = $this->makePo([
            ['name' => 'Màn hình HP',   'unit_price' => 2231482, 'vat_rate' => 8, 'qty' => 2],
            ['name' => 'Bộ lưu điện',  'unit_price' => 7481481, 'vat_rate' => 8, 'qty' => 1],
        ]);
        $entry = $this->createEntry($po);

        $response = $this->get(route('warehouse.stock-entries.show', $entry->id));
        $items = $response->original->getData()['page']['props']['entry']['items'];

        $grandTotal = array_sum(array_column($items, 'total'));
        // Should be ~12_900_000 (within ±10 due to per-line rounding)
        $this->assertEqualsWithDelta(12900000, $grandTotal, 10);

        // total must be > subtotal_excl (VAT was added, not subtracted)
        foreach ($items as $item) {
            $this->assertGreaterThan($item['subtotal_excl'], $item['total']);
        }
    }

    public function test_show_partial_receipt_total_proportional(): void
    {
        // PO has qty=4, we receive qty=2 → total should be for qty=2 only
        $po = $this->makePo([['name' => 'SP D', 'unit_price' => 5000000, 'vat_rate' => 10, 'qty' => 4]]);

        $poItem = $po->items->first();
        $entry  = $this->createEntry($po, [$poItem->product_id => ['quantity' => 2]]);

        $response = $this->get(route('warehouse.stock-entries.show', $entry->id));
        $items = $response->original->getData()['page']['props']['entry']['items'];

        $this->assertEquals(10000000, $items[0]['subtotal_excl']); // 2 × 5_000_000
        $this->assertEquals(1000000,  $items[0]['tax_amount']);
        $this->assertEquals(11000000, $items[0]['total']);
    }

    public function test_show_reload_after_save_shows_correct_total(): void
    {
        $po    = $this->makePo([['name' => 'SP E', 'unit_price' => 3000000, 'vat_rate' => 10, 'qty' => 3]]);
        $entry = $this->createEntry($po);

        // Reload from DB and re-check show page
        $reloaded = StockEntry::find($entry->id);
        $response = $this->get(route('warehouse.stock-entries.show', $reloaded->id));
        $items = $response->original->getData()['page']['props']['entry']['items'];

        $this->assertEquals(9000000,  $items[0]['subtotal_excl']);
        $this->assertEquals(900000,   $items[0]['tax_amount']);
        $this->assertEquals(9900000,  $items[0]['total']);
    }

    // ── Accounting journal (postEntryJournal) ─────────────────────────────────

    public function test_journal_cr331_equals_total_incl_vat(): void
    {
        // unit_price EXCL VAT = 5_000_000, qty=1, VAT 10%
        // Expect: Dr 156 = 5_000_000, Dr 1331 = 500_000, Cr 331 = 5_500_000
        $po    = $this->makePo([['name' => 'SP F', 'unit_price' => 5000000, 'vat_rate' => 10, 'qty' => 1]]);
        $entry = $this->createEntry($po);

        $this->post(route('warehouse.stock-entries.confirm', $entry->id));

        $journal = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
            ->where('reference_id', $entry->id)
            ->first();

        $this->assertNotNull($journal);

        $lines  = $journal->lines;
        $cr3311 = $lines->where('account_code', '3311')->sum('credit');
        $dr1561 = $lines->where('account_code', '1561')->sum('debit');
        $dr1331 = $lines->where('account_code', '1331')->sum('debit');

        $this->assertEquals(5000000, $dr1561);
        $this->assertEquals(500000,  $dr1331);
        $this->assertEquals(5500000, $cr3311);
    }

    public function test_journal_cr331_vat_8_percent(): void
    {
        // unit_price = 2_231_482, qty=2, VAT 8%
        // Dr 1561 = 4_462_964, Dr 1331 = 357_037, Cr 3311 = 4_820_001
        $po    = $this->makePo([['name' => 'SP G', 'unit_price' => 2231482, 'vat_rate' => 8, 'qty' => 2]]);
        $entry = $this->createEntry($po);
        $this->post(route('warehouse.stock-entries.confirm', $entry->id));

        $journal = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
            ->where('reference_id', $entry->id)->first();

        $lines  = $journal->lines;
        $cr3311 = $lines->where('account_code', '3311')->sum('credit');
        $dr1561 = $lines->where('account_code', '1561')->sum('debit');
        $dr1331 = $lines->where('account_code', '1331')->sum('debit');

        $this->assertEquals(4462964, $dr1561);
        $this->assertEqualsWithDelta(357037, $dr1331, 1);
        $this->assertEquals($dr1561 + $dr1331, $cr3311); // journal must balance
    }

    public function test_journal_balanced_multi_item_mixed_vat(): void
    {
        $po = $this->makePo([
            ['name' => 'SP H', 'unit_price' => 1000000, 'vat_rate' => 10, 'qty' => 3],
            ['name' => 'SP I', 'unit_price' => 2000000, 'vat_rate' => 0,  'qty' => 2],
        ]);
        $entry = $this->createEntry($po);
        $this->post(route('warehouse.stock-entries.confirm', $entry->id));

        $journal = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
            ->where('reference_id', $entry->id)->first();

        $lines      = $journal->lines;
        $totalDebit = $lines->sum('debit');
        $totalCredit = $lines->sum('credit');
        $this->assertEquals($totalDebit, $totalCredit); // journal is balanced

        $cr3311 = $lines->where('account_code', '3311')->sum('credit');
        // SP H: 3×1_000_000×1.10 = 3_300_000, SP I: 2×2_000_000 = 4_000_000 → total = 7_300_000
        $this->assertEquals(7300000, $cr3311);
    }
}
