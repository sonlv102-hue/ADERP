<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CogsAndRevenueAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private InvoiceService $invoiceService;
    private Customer $customer;
    private int $seqProduct = 0;
    private int $seqOrder   = 0;
    private int $seqInvoice = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->invoiceService = app(InvoiceService::class);

        foreach ([
            ['code' => '131',   'name' => 'Phải thu KH',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => false],
            ['code' => '1311',  'name' => 'Phải thu KH - DN', 'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '5111',  'name' => 'DT hàng hóa',      'type' => 'revenue',   'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '5113',  'name' => 'DT dịch vụ',       'type' => 'revenue',   'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, [
                'level' => 3, 'parent_code' => null,
            ]));
        }

        $this->customer = Customer::create([
            'code'                    => 'KH-0001',
            'name'                    => 'Test Customer',
            'receivable_account_code' => '1311',
        ]);

        // Kỳ kế toán mở tháng 6/2026 — cần để AccountingService::checkPeriodOpen() không throw
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
    }

    private function makeProduct(int $costPrice, int $vatPercent): Product
    {
        $this->seqProduct++;
        return Product::create([
            'code'        => "SP-TEST-{$this->seqProduct}",
            'name'        => "Test Product {$this->seqProduct}",
            'cost_price'  => $costPrice,
            'vat_percent' => $vatPercent,
        ]);
    }

    private function makeOrder(): Order
    {
        $this->seqOrder++;
        return Order::create([
            'code'        => "DH-TEST-{$this->seqOrder}",
            'customer_id' => $this->customer->id,
            'created_by'  => $this->user->id,
            'status'      => 'pending',
            'order_date'  => '2026-06-01',
        ]);
    }

    private function makeInvoice(?int $orderId, int $subtotal, int $tax, ?string $revenueAccount = null): Invoice
    {
        $this->seqInvoice++;
        return Invoice::create([
            'code'                 => "HĐ-TEST-{$this->seqInvoice}",
            'customer_id'          => $this->customer->id,
            'order_id'             => $orderId,
            'subtotal'             => $subtotal,
            'tax_amount'           => $tax,
            'total'                => $subtotal + $tax,
            'revenue_account_code' => $revenueAccount,
            'status'               => 'draft',
            'created_by'           => $this->user->id,
            'issue_date'           => '2026-06-01',
        ]);
    }

    private function insertOrderItem(int $orderId, float $unitPrice, ?string $revenueAccount, array $extra = []): int
    {
        return DB::table('order_items')->insertGetId(array_merge([
            'order_id'             => $orderId,
            'name'                 => 'Test item',
            'quantity'             => 1,
            'unit_price'           => $unitPrice,
            'revenue_account_code' => $revenueAccount,
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $extra));
    }

    // ─── H1: COGS snapshot ──────────────────────────────────────────────────

    /**
     * H1-TC1: unit_cogs snapshot không thay đổi khi cost_price sản phẩm thay đổi.
     */
    public function test_new_order_snapshots_unit_cogs_and_is_immutable(): void
    {
        $product = $this->makeProduct(1_100_000, 10);
        $order   = $this->makeOrder();

        $itemId = $this->insertOrderItem($order->id, 1_500_000, '5111', [
            'product_id'       => $product->id,
            'unit_cogs'        => round(1_100_000 / 1.10, 2),
            'unit_cogs_source' => 'snapshot',
            'quantity'         => 2,
        ]);

        $item = DB::table('order_items')->find($itemId);
        $this->assertEquals(1_000_000.0, (float) $item->unit_cogs, 'unit_cogs phải = cost_price/1.10');
        $this->assertEquals('snapshot', $item->unit_cogs_source);

        $product->update(['cost_price' => 1_320_000]);

        $item = DB::table('order_items')->find($itemId);
        $this->assertEquals(1_000_000.0, (float) $item->unit_cogs, 'unit_cogs đã snapshot không được thay đổi');
        $this->assertEquals('snapshot', $item->unit_cogs_source);
    }

    /**
     * H1-TC2: order_items backfill được đánh dấu 'backfill_estimated'.
     */
    public function test_backfill_estimated_flag_is_set_on_old_orders(): void
    {
        $product = $this->makeProduct(550_000, 10);
        $order   = $this->makeOrder();

        $itemId = $this->insertOrderItem($order->id, 700_000, null, [
            'product_id'       => $product->id,
            'unit_cogs'        => round(550_000 / 1.10, 2),
            'unit_cogs_source' => 'backfill_estimated',
        ]);

        $item = DB::table('order_items')->find($itemId);
        $this->assertEquals('backfill_estimated', $item->unit_cogs_source);
    }

    /**
     * H1-TC3: vat_percent = 0 → unit_cogs = cost_price (vatDiv = 1.0).
     */
    public function test_product_with_zero_vat_cogs_equals_cost_price(): void
    {
        $product  = $this->makeProduct(3_000_000, 0);
        $vatDiv   = 1 + (float) $product->vat_percent / 100;
        $unitCogs = round((float) $product->cost_price / $vatDiv, 2);

        $this->assertEquals(3_000_000.0, $unitCogs, 'unit_cogs = cost_price khi vat_percent = 0');
    }

    // ─── M1: Revenue account mapping ────────────────────────────────────────

    /**
     * M1-TC1: Invoice order chỉ có hàng hóa → Có TK 5111.
     */
    public function test_invoice_goods_only_posts_to_5111(): void
    {
        $order = $this->makeOrder();
        $this->insertOrderItem($order->id, 10_000_000, '5111');

        $invoice = $this->makeInvoice($order->id, 10_000_000, 1_000_000);
        $this->invoiceService->markSent($invoice);

        $jel = JournalEntryLine::whereHas('entry', fn($q) => $q
            ->where('reference_type', 'invoice')->where('reference_id', $invoice->id)
        )->where('account_code', '5111')->first();

        $this->assertNotNull($jel, 'Phải có dòng bút toán Có TK 5111');
        $this->assertEquals(10_000_000, (int) $jel->credit);
    }

    /**
     * M1-TC2: Invoice order chỉ có dịch vụ → Có TK 5113.
     */
    public function test_invoice_service_only_posts_to_5113(): void
    {
        $order = $this->makeOrder();
        $this->insertOrderItem($order->id, 8_000_000, '5113');

        $invoice = $this->makeInvoice($order->id, 8_000_000, 800_000);
        $this->invoiceService->markSent($invoice);

        $jel = JournalEntryLine::whereHas('entry', fn($q) => $q
            ->where('reference_type', 'invoice')->where('reference_id', $invoice->id)
        )->where('account_code', '5113')->first();

        $this->assertNotNull($jel, 'Phải có dòng bút toán Có TK 5113');
        $this->assertEquals(8_000_000, (int) $jel->credit);
    }

    /**
     * M1-TC3: Invoice order hỗn hợp → tách đúng 5111 và 5113, tổng = subtotal.
     */
    public function test_invoice_mixed_splits_revenue_correctly(): void
    {
        $order = $this->makeOrder();
        $this->insertOrderItem($order->id, 10_000_000, '5111');
        $this->insertOrderItem($order->id, 5_000_000, '5113');

        $invoice = $this->makeInvoice($order->id, 15_000_000, 1_500_000);
        $this->invoiceService->markSent($invoice);

        $jeId = JournalEntry::where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)->value('id');
        $this->assertNotNull($jeId, 'Journal entry phải tồn tại');

        $lines = JournalEntryLine::where('journal_entry_id', $jeId)
            ->whereIn('account_code', ['5111', '5113'])
            ->pluck('credit', 'account_code');

        $this->assertArrayHasKey('5111', $lines->toArray(), '5111 phải có dòng bút toán');
        $this->assertArrayHasKey('5113', $lines->toArray(), '5113 phải có dòng bút toán');
        $this->assertEquals(15_000_000, (int) ($lines['5111'] + $lines['5113']), 'Tổng revenue phải = subtotal');
    }

    /**
     * M1-TC4: Standalone invoice có revenue_account_code → dùng đúng TK, không fallback.
     */
    public function test_standalone_invoice_with_revenue_account_uses_correct_account(): void
    {
        $invoice = $this->makeInvoice(null, 5_000_000, 500_000, '5113');
        $this->invoiceService->markSent($invoice);

        $jel = JournalEntryLine::whereHas('entry', fn($q) => $q
            ->where('reference_type', 'invoice')->where('reference_id', $invoice->id)
        )->where('account_code', '5113')->first();

        $this->assertNotNull($jel, 'Phải dùng 5113, không fallback về 5111');
        $this->assertEquals(5_000_000, (int) $jel->credit);
    }

    /**
     * M1-TC5: Standalone invoice thiếu revenue_account_code → fallback về 5111.
     */
    public function test_standalone_invoice_missing_revenue_account_falls_back_to_5111(): void
    {
        $invoice = $this->makeInvoice(null, 3_000_000, 300_000, null);
        $this->invoiceService->markSent($invoice);

        $jel = JournalEntryLine::whereHas('entry', fn($q) => $q
            ->where('reference_type', 'invoice')->where('reference_id', $invoice->id)
        )->where('account_code', '5111')->first();

        $this->assertNotNull($jel, 'Fallback phải vào 5111');
        $this->assertEquals(3_000_000, (int) $jel->credit);
    }
}
