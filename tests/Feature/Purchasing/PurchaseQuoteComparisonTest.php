<?php

namespace Tests\Feature\Purchasing;

use App\Models\Product;
use App\Models\PurchaseQuoteComparison;
use App\Models\PurchaseSupplierQuote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * PURCHASE-QUOTE-COMP-T01 — TC01..TC15
 */
class PurchaseQuoteComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private array $products = [];

    private const MARKER = ['MiniERP Supplier Quote', 'Version: 1', '', '', '', '', '', '', '', '', ''];
    private const HEADER = [
        'Mã hàng*', 'Tên hàng', 'Quy cách', 'ĐVT*', 'SL yêu cầu*',
        'Đơn giá*', 'CK %', 'VAT %', 'Thời gian giao', 'Bảo hành', 'Ghi chú',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        for ($i = 1; $i <= 12; $i++) {
            $code = 'SP-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $this->products[$code] = Product::create([
                'code' => $code, 'name' => "Vật tư {$i}", 'unit' => 'cái',
                'cost_price' => 1000 * $i, 'is_active' => true,
            ]);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeComparison(int $itemCount = 10): PurchaseQuoteComparison
    {
        $c = app(\App\Services\PurchaseQuoteComparisonService::class)->create([
            'name' => 'Đợt test', 'comparison_date' => '2026-08-01',
        ]);
        $i = 0;
        foreach ($this->products as $code => $p) {
            if ($i++ >= $itemCount) break;
            app(\App\Services\PurchaseQuoteComparisonService::class)
                ->addItem($c, $p->id, 10, null, null);
        }
        return $c->fresh();
    }

    private function xlsx(array $dataRows, string $name = 'bg.xlsx', bool $withMarker = true): UploadedFile
    {
        $rows = $withMarker
            ? array_merge([self::MARKER, self::HEADER], $dataRows)
            : array_merge([self::HEADER], $dataRows);
        $export = new class($rows) implements FromArray {
            public function __construct(private array $rows) {}
            public function array(): array { return $this->rows; }
        };
        Excel::store($export, $name, 'local');

        return new UploadedFile(Storage::disk('local')->path($name), $name, null, null, true);
    }

    private function previewQuote(PurchaseQuoteComparison $c, Supplier $s, array $dataRows): array
    {
        return $this->post(
            route('purchasing.quote-comparisons.quotes.preview', $c->id),
            ['supplier_id' => $s->id, 'file' => $this->xlsx($dataRows)],
            ['Accept' => 'application/json']
        )->json() ?? [];
    }

    private function line(string $code, float $price, float $ck = 0, float $vat = 10, float $qty = 10): array
    {
        return [$code, 'x', '', 'cái', $qty, $price, $ck, $vat, '', '', ''];
    }

    // ─── tests ──────────────────────────────────────────────────────────────

    /** TC01 */
    public function test_tc01_create_comparison_with_10_items(): void
    {
        $c = $this->makeComparison(10);
        $this->assertCount(10, $c->items);
        $this->assertStringStartsWith('SSBG-', $c->code);
    }

    /** TC02 */
    public function test_tc02_quote_template_contains_items(): void
    {
        $c = $this->makeComparison(3);
        $res = $this->get(route('purchasing.quote-comparisons.quote-template', $c->id));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheet', $res->headers->get('content-type'));
    }

    /** TC03 */
    public function test_tc03_import_full_10_lines(): void
    {
        $c = $this->makeComparison(10);
        $s = Supplier::create(['code' => 'NCC-A', 'name' => 'NCC A']);
        $rows = collect($c->items)->map(fn ($it) => $this->line($it->product_code_snapshot, 5000))->all();

        $preview = $this->previewQuote($c, $s, $rows);
        $this->assertSame(10, $preview['valid_rows']);
        $this->assertTrue($preview['can_confirm']);
    }

    /** TC04 */
    public function test_tc04_partial_quote_shows_dash(): void
    {
        $c = $this->makeComparison(10);
        $s = Supplier::create(['code' => 'NCC-B', 'name' => 'NCC B']);
        $rows = collect($c->items)->take(8)->map(fn ($it) => $this->line($it->product_code_snapshot, 5000))->all();

        $preview = $this->previewQuote($c, $s, $rows);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $preview['preview_id']])->assertOk();

        $matrix = app(\App\Services\PurchaseQuoteComparisonMatrixService::class)->buildMatrix($c->fresh());
        $missing = collect($matrix['items'])->filter(fn ($i) => empty($i['cells']))->count();
        $this->assertSame(2, $missing);
    }

    /** TC05 */
    public function test_tc05_product_not_in_comparison_is_error(): void
    {
        $c = $this->makeComparison(3);
        $s = Supplier::create(['code' => 'NCC-C', 'name' => 'NCC C']);
        $rows = [$this->line('SP-0009', 5000)]; // exists but not in comparison

        $preview = $this->previewQuote($c, $s, $rows);
        $this->assertFalse($preview['can_confirm']);
        $this->assertNotEmpty($preview['errors']);
    }

    /** TC06 */
    public function test_tc06_duplicate_code_blocks_confirm(): void
    {
        $c = $this->makeComparison(3);
        $s = Supplier::create(['code' => 'NCC-D', 'name' => 'NCC D']);
        $rows = [$this->line('SP-0001', 5000), $this->line('SP-0001', 6000)];

        $preview = $this->previewQuote($c, $s, $rows);
        $this->assertFalse($preview['can_confirm']);

        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $preview['preview_id']])
            ->assertStatus(422);
    }

    /** TC07 */
    public function test_tc07_net_price_after_discount(): void
    {
        $c = $this->makeComparison(1);
        $s = Supplier::create(['code' => 'NCC-E', 'name' => 'NCC E']);
        $preview = $this->previewQuote($c, $s, [$this->line('SP-0001', 100000, 10)]);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $preview['preview_id']])->assertOk();

        $this->assertEquals(90000, PurchaseSupplierQuote::first()->lines()->first()->net_unit_price);
    }

    /** TC08 */
    public function test_tc08_lowest_price_detection(): void
    {
        $c = $this->makeComparison(1);
        foreach ([['NCC-F', 12000], ['NCC-G', 9000], ['NCC-H', 15000]] as [$code, $price]) {
            $s = Supplier::create(['code' => $code, 'name' => $code]);
            $preview = $this->previewQuote($c, $s, [$this->line('SP-0001', $price, 0, 0)]);
            $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $preview['preview_id']])->assertOk();
        }

        $matrix = app(\App\Services\PurchaseQuoteComparisonMatrixService::class)->buildMatrix($c->fresh());
        $item = $matrix['items'][0];
        $this->assertEquals(9000, $item['lowest_price']);
        $this->assertEquals(Supplier::where('code', 'NCC-G')->first()->id, $item['lowest_supplier_id']);
    }

    /** TC09 */
    public function test_tc09_non_lowest_selection_requires_reason(): void
    {
        $c = $this->makeComparison(1);
        $cheap = Supplier::create(['code' => 'NCC-I', 'name' => 'Rẻ']);
        $pricey = Supplier::create(['code' => 'NCC-J', 'name' => 'Đắt']);
        foreach ([[$cheap, 9000], [$pricey, 12000]] as [$s, $price]) {
            $p = $this->previewQuote($c, $s, [$this->line('SP-0001', $price, 0, 0)]);
            $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();
        }

        $item = $c->fresh()->items->first();
        $line = PurchaseSupplierQuote::where('supplier_id', $pricey->id)->first()->lines()->first();

        // thiếu lý do → lỗi
        $this->from(route('purchasing.quote-comparisons.show', $c->id))
            ->post(route('purchasing.quote-comparisons.items.selection', ['quoteComparison' => $c->id, 'item' => $item->id]), [
                'quote_line_id' => $line->id,
            ])->assertSessionHas('error');

        // có lý do → OK
        $this->post(route('purchasing.quote-comparisons.items.selection', ['quoteComparison' => $c->id, 'item' => $item->id]), [
            'quote_line_id' => $line->id, 'selection_reason' => 'faster_delivery',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('purchase_quote_selections', [
            'comparison_item_id' => $item->id, 'supplier_id' => $pricey->id, 'is_lowest_price' => false,
        ]);
    }

    /** TC10 */
    public function test_tc10_reimport_creates_version_2(): void
    {
        $c = $this->makeComparison(2);
        $s = Supplier::create(['code' => 'NCC-K', 'name' => 'NCC K']);

        foreach ([5000, 4800] as $price) {
            $rows = collect($c->items)->map(fn ($it) => $this->line($it->product_code_snapshot, $price))->all();
            $p = $this->previewQuote($c, $s, $rows);
            $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();
        }

        $quotes = PurchaseSupplierQuote::where('supplier_id', $s->id)->orderBy('version_no')->get();
        $this->assertCount(2, $quotes);
        $this->assertFalse($quotes[0]->is_active_version);
        $this->assertTrue($quotes[1]->is_active_version);
        $this->assertSame(2, $quotes[1]->version_no);
    }

    /** TC11 */
    public function test_tc11_missing_price_is_error(): void
    {
        $c = $this->makeComparison(2);
        $s = Supplier::create(['code' => 'NCC-L', 'name' => 'NCC L']);
        $rows = [
            ['SP-0001', 'x', '', 'cái', 10, '', 0, 10, '', '', ''],   // thiếu đơn giá
            $this->line('SP-0002', 5000),
        ];
        $preview = $this->previewQuote($c, $s, $rows);
        $this->assertGreaterThanOrEqual(1, $preview['error_rows']);
        $this->assertFalse($preview['can_confirm']);
    }

    /** TC11b — file sai mẫu (không có dấu nhận diện) bị từ chối */
    public function test_tc11b_wrong_template_rejected(): void
    {
        $c = $this->makeComparison(2);
        $s = Supplier::create(['code' => 'NCC-L2', 'name' => 'NCC L2']);
        $file = $this->xlsx([$this->line('SP-0001', 5000)], 'sai-mau.xlsx', withMarker: false);

        $res = $this->post(
            route('purchasing.quote-comparisons.quotes.preview', $c->id),
            ['supplier_id' => $s->id, 'file' => $file],
            ['Accept' => 'application/json'],
        );

        $res->assertStatus(422);
        $this->assertStringContainsString('không đúng mẫu', $res->json('message'));
    }

    /** TC12 */
    public function test_tc12_download_original_file(): void
    {
        $c = $this->makeComparison(1);
        $s = Supplier::create(['code' => 'NCC-M', 'name' => 'NCC M']);
        $p = $this->previewQuote($c, $s, [$this->line('SP-0001', 5000)]);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();

        $quote = PurchaseSupplierQuote::first();
        $this->assertNotNull($quote->stored_file_path);
        $this->get(route('purchasing.quote-comparisons.quotes.file', ['quoteComparison' => $c->id, 'quote' => $quote->id]))
            ->assertOk();
    }

    /** TC13 */
    public function test_tc13_selection_summary_totals(): void
    {
        $c = $this->makeComparison(2);
        $s = Supplier::create(['code' => 'NCC-N', 'name' => 'NCC N']);
        $rows = collect($c->items)->map(fn ($it) => $this->line($it->product_code_snapshot, 5000, 0, 0))->all();
        $p = $this->previewQuote($c, $s, $rows);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();

        foreach ($c->fresh()->items as $item) {
            $line = $item->quoteLines()->first();
            $this->post(route('purchasing.quote-comparisons.items.selection', ['quoteComparison' => $c->id, 'item' => $item->id]), [
                'quote_line_id' => $line->id,
            ]);
        }

        $summary = app(\App\Services\PurchaseQuoteComparisonMatrixService::class)->selectionSummary($c->fresh());
        $this->assertEquals(100000, $summary['selection_subtotal']); // 2 * 10 * 5000
        $this->assertTrue($summary['is_all_lowest']);
    }

    /** TC14 */
    public function test_tc14_export_result(): void
    {
        $c = $this->makeComparison(2);
        $s = Supplier::create(['code' => 'NCC-O', 'name' => 'NCC O']);
        $rows = collect($c->items)->map(fn ($it) => $this->line($it->product_code_snapshot, 5000))->all();
        $p = $this->previewQuote($c, $s, $rows);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();

        $this->get(route('purchasing.quote-comparisons.export', $c->id))->assertOk();
    }

    /** TC15 — no accounting / stock / PO side effects */
    public function test_tc15_no_side_effects(): void
    {
        $c = $this->makeComparison(3);
        $s = Supplier::create(['code' => 'NCC-P', 'name' => 'NCC P']);
        $rows = collect($c->items)->map(fn ($it) => $this->line($it->product_code_snapshot, 5000))->all();
        $p = $this->previewQuote($c, $s, $rows);
        $this->postJson(route('purchasing.quote-comparisons.quotes.confirm', $c->id), ['preview_id' => $p['preview_id']])->assertOk();

        $item = $c->fresh()->items->first();
        $this->post(route('purchasing.quote-comparisons.items.selection', ['quoteComparison' => $c->id, 'item' => $item->id]), [
            'quote_line_id' => $item->quoteLines()->first()->id,
        ]);

        $this->assertSame(0, \App\Models\StockMovement::count());
        $this->assertSame(0, \App\Models\JournalEntry::count());
        $this->assertSame(0, \App\Models\PurchaseOrder::count());
        $this->assertSame(0, \App\Models\CashVoucher::count());
    }
}
