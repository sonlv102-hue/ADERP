<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VAT per-line trên hóa đơn bán hàng.
 * Đảm bảo: tổng VAT tính từ các dòng = invoice.tax_amount = Cr 33311 trên JE.
 */
class InvoiceItemVatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private InvoiceService $invoiceService;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->invoiceService = app(InvoiceService::class);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'sales.invoices.create', 'guard_name' => 'web']);
        $this->user->givePermissionTo(['accounting.view', 'accounting.manage', 'sales.invoices.create']);

        foreach ([
            ['code' => '131',   'name' => 'Phải thu KH',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => false],
            ['code' => '1311',  'name' => 'Phải thu KH - DN', 'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '5111',  'name' => 'DT hàng hóa',      'type' => 'revenue',   'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['level' => 3, 'parent_code' => null]));
        }

        $this->customer = Customer::create([
            'code'                    => 'KH-VAT-01',
            'name'                    => 'Test Customer VAT',
            'receivable_account_code' => '1311',
        ]);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
    }

    private function storeInvoice(array $items, array $overrides = []): Invoice
    {
        $payload = array_merge([
            'code'                 => 'HD-IT-' . str_pad(++$this->seq, 3, '0', STR_PAD_LEFT),
            'customer_id'          => $this->customer->id,
            'issue_date'           => '2026-06-01',
            'revenue_account_code' => '5111',
            'items'                => $items,
        ], $overrides);

        $this->post(route('accounting.invoices.store'), $payload)->assertRedirect();

        return Invoice::latest('id')->firstOrFail();
    }

    // ─── TC1: dòng đơn — 10% ─────────────────────────────────────────────────

    public function test_single_line_10_percent(): void
    {
        // subtotal = 1_000_000, tax = round(1_000_000 * 10 / 100) = 100_000
        $invoice = $this->storeInvoice([
            ['description' => 'Hàng A', 'quantity' => 1, 'unit_price' => 1_000_000, 'vat_rate' => 10],
        ]);

        $this->assertEquals(1_000_000.0, (float) $invoice->subtotal);
        $this->assertEquals(100_000,     $invoice->tax_amount);
        $this->assertEquals(1_100_000.0, (float) $invoice->total);

        $item = InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(100_000, $item->tax_amount);
    }

    // ─── TC2: dòng đơn — 8% ──────────────────────────────────────────────────

    public function test_single_line_8_percent(): void
    {
        $invoice = $this->storeInvoice([
            ['description' => 'Hàng B', 'quantity' => 1, 'unit_price' => 1_000_000, 'vat_rate' => 8],
        ]);

        $this->assertEquals(80_000, $invoice->tax_amount);
        $this->assertEquals(1_080_000.0, (float) $invoice->total);
    }

    // ─── TC3: dòng đơn — 0% (miễn VAT) ──────────────────────────────────────

    public function test_single_line_exempt(): void
    {
        $invoice = $this->storeInvoice([
            ['description' => 'Dịch vụ miễn VAT', 'quantity' => 1, 'unit_price' => 500_000, 'vat_rate' => 0],
        ]);

        $this->assertEquals(0,         $invoice->tax_amount);
        $this->assertEquals(500_000.0, (float) $invoice->total);
    }

    // ─── TC4: nhiều dòng, thuế suất KHÁC NHAU ────────────────────────────────
    // Dòng 1: 2_000_000 x 10% = 200_000
    // Dòng 2: 1_000_000 x 8%  =  80_000
    // Dòng 3:   500_000 x 0%  =       0
    // Tổng:   3_500_000       = 280_000

    public function test_multi_line_mixed_vat_rates(): void
    {
        $invoice = $this->storeInvoice([
            ['description' => 'Hàng 10%', 'quantity' => 2, 'unit_price' => 1_000_000, 'vat_rate' => 10],
            ['description' => 'Hàng 8%',  'quantity' => 1, 'unit_price' => 1_000_000, 'vat_rate' => 8],
            ['description' => 'Miễn VAT', 'quantity' => 1, 'unit_price' =>   500_000, 'vat_rate' => 0],
        ]);

        $this->assertEquals(3_500_000.0, (float) $invoice->subtotal, 'subtotal = sum(qty*price)');
        $this->assertEquals(280_000,     $invoice->tax_amount,       'tax = 200_000 + 80_000 + 0');
        $this->assertEquals(3_780_000.0, (float) $invoice->total,    'total = subtotal + tax');

        // Kiểm tra per-line tax_amount được lưu đúng
        $items = InvoiceItem::where('invoice_id', $invoice->id)->orderBy('sort_order')->get();
        $this->assertEquals(200_000, $items[0]->tax_amount, 'dòng 1 tax');
        $this->assertEquals(80_000,  $items[1]->tax_amount, 'dòng 2 tax');
        $this->assertEquals(0,       $items[2]->tax_amount, 'dòng 3 tax');
    }

    // ─── TC5: làm tròn per-line rồi cộng (không phải tính trên tổng) ─────────
    // Nếu tính trên tổng: round(1_000_001 * 10 / 100) = 100_000
    // Nếu tính per-line:  round(1_000_001 * 10 / 100) = 100_000 (trường hợp này giống nhau)
    // Dùng ví dụ cụ thể hơn: 2 dòng, mỗi dòng 33_333 x 10% = 3_333 → tổng = 6_666
    // Nhưng nếu tính trên tổng: round(66_666 * 10%) = 6_667 ← KHÁC!

    public function test_rounding_per_line_not_on_total(): void
    {
        // Dòng 1: 33_333 x 10% = round(3_333.3) = 3_333
        // Dòng 2: 33_333 x 10% = round(3_333.3) = 3_333
        // Tổng per-line: 6_666
        // Tính trên tổng: round(66_666 * 10%) = 6_667 ← sẽ sai

        $invoice = $this->storeInvoice([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => 33_333, 'vat_rate' => 10],
            ['description' => 'B', 'quantity' => 1, 'unit_price' => 33_333, 'vat_rate' => 10],
        ]);

        $this->assertEquals(6_666, $invoice->tax_amount, 'per-line: 3333 + 3333 = 6666 (không phải 6667)');

        $items = InvoiceItem::where('invoice_id', $invoice->id)->orderBy('sort_order')->get();
        $this->assertEquals(3_333, $items[0]->tax_amount);
        $this->assertEquals(3_333, $items[1]->tax_amount);
    }

    // ─── TC6: server override client tax_amount sai ───────────────────────────
    // Client gửi tax_amount sai → server tính lại từ items, ghi đúng vào DB

    public function test_server_overrides_wrong_client_tax(): void
    {
        // Client cố tình gửi tax_amount sai trong item (server sẽ ignore)
        // và subtotal/total sai (server tính lại)
        $payload = [
            'code'                 => 'HD-IT-OVR',
            'customer_id'          => $this->customer->id,
            'issue_date'           => '2026-06-01',
            'revenue_account_code' => '5111',
            'subtotal'             => 999,     // client gửi sai — server bỏ qua
            'tax_amount'           => 999_999, // client gửi sai — server bỏ qua
            'total'                => 1_000,   // client gửi sai — server bỏ qua
            'items'                => [
                ['description' => 'X', 'quantity' => 1, 'unit_price' => 2_000_000, 'vat_rate' => 10, 'tax_amount' => 999],
            ],
        ];

        $this->post(route('accounting.invoices.store'), $payload)->assertRedirect();
        $invoice = Invoice::latest('id')->firstOrFail();

        // Server tính: 2_000_000 * 10% = 200_000
        $this->assertEquals(200_000,     $invoice->tax_amount, 'server phải tính lại tax từ items');
        $this->assertEquals(2_000_000.0, (float) $invoice->subtotal);
        $this->assertEquals(2_200_000.0, (float) $invoice->total);

        $item = InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(200_000, $item->tax_amount, 'per-line tax phải được server tính');
    }

    // ─── TC7: VAT trên JE = tổng tax per-line ────────────────────────────────
    // Sau markSent(), Cr 33311 = SUM(invoice_items.tax_amount)

    public function test_je_cr_33311_equals_sum_of_line_taxes(): void
    {
        // 2 dòng: 1_000_000 x 10% = 100_000 và 500_000 x 8% = 40_000 → tổng = 140_000
        $invoice = $this->storeInvoice([
            ['description' => 'A', 'quantity' => 1, 'unit_price' => 1_000_000, 'vat_rate' => 10],
            ['description' => 'B', 'quantity' => 1, 'unit_price' =>   500_000, 'vat_rate' => 8],
        ]);

        $this->assertEquals(140_000, $invoice->tax_amount, 'invoice.tax_amount = 100_000 + 40_000');

        $this->invoiceService->markSent($invoice);

        // JE từ markSent() có thể ở trạng thái draft (isAuto=true trong tryPost)
        $taxCredit = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.reference_type', 'invoice')
            ->where('je.reference_id', $invoice->id)
            ->whereIn('je.status', ['draft', 'posted'])
            ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            ->where('jl.account_code', '33311')
            ->sum('jl.credit');

        $this->assertEquals(140_000.0, (float) $taxCredit,
            'Cr 33311 phải = 140_000 (tổng tax per-line), khớp tới đồng');
    }

    // ─── TC9: invoice gắn order — Cr 33311 phải theo invoice_items, không order_items ─
    // Bối cảnh: invoice có order_id; buildRevenueLines() đọc order_items để phân bổ TK 511x.
    // Câu hỏi: nếu invoice_items.vat_rate KHÁC order_items.vat_rate, Cr 33311 theo ai?
    // Đáp án đúng: Cr 33311 = invoice.tax_amount = SUM(invoice_items.tax_amount) — không phải order_items.
    // Kiểm chứng bằng cách dùng trực tiếp model để bypass HTTP guard (order.total check).

    public function test_cr_33311_follows_invoice_items_not_order_items(): void
    {
        // order với 1 item vat_rate=10% (order.total không cần set — test bypass HTTP)
        $order = Order::create([
            'code'        => 'DH-VAT-TEST',
            'customer_id' => $this->customer->id,
            'status'      => 'pending',
            'order_date'  => '2026-06-01',
            'created_by'  => $this->user->id,
        ]);
        OrderItem::create([
            'order_id'             => $order->id,
            'name'                 => 'Hàng X',
            'quantity'             => 1,
            'unit_price'           => 1_000_000,
            'vat_rate'             => 10,
            'revenue_account_code' => '5111',
        ]);

        // Tạo invoice gắn order, nhưng invoice_items dùng vat_rate=8% (user sửa khác order)
        // Dùng model trực tiếp để không bị block bởi order.total guard ở HTTP layer
        $invoice = Invoice::create([
            'code'                 => 'HD-IT-OTR',
            'customer_id'          => $this->customer->id,
            'order_id'             => $order->id,
            'issue_date'           => '2026-06-01',
            'revenue_account_code' => '5111',
            'status'               => \App\Enums\InvoiceStatus::Draft,
            'subtotal'             => 1_000_000,
            'tax_amount'           => 80_000,   // 8% — KHÁC với order_items (10%)
            'total'                => 1_080_000,
            'created_by'           => $this->user->id,
        ]);
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'sort_order'  => 0,
            'description' => 'Hàng X (đã sửa thuế)',
            'quantity'    => 1,
            'unit_price'  => 1_000_000,
            'vat_rate'    => 8,
            'tax_amount'  => 80_000,
        ]);

        $this->assertEquals(80_000, (int) $invoice->tax_amount, 'invoice.tax_amount = 80_000 (8%)');

        // markSent: postInvoiceEntry() đọc invoice.tax_amount → Cr 33311 phải = 80_000
        // Không phải 100_000 (order_items 10%)
        $this->invoiceService->markSent($invoice);

        $taxCredit = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.reference_type', 'invoice')
            ->where('je.reference_id', $invoice->id)
            ->whereIn('je.status', ['draft', 'posted'])
            ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            ->where('jl.account_code', '33311')
            ->sum('jl.credit');

        $this->assertEquals(80_000.0, (float) $taxCredit,
            'Cr 33311 phải = 80_000 (invoice_items 8%), không phải 100_000 (order_items 10%)');
    }

    // ─── TC8: khách hàng miễn VAT — tất cả dòng 0% → JE không có Cr 33311 ──

    public function test_all_zero_vat_no_33311_line(): void
    {
        $invoice = $this->storeInvoice([
            ['description' => 'DV miễn thuế', 'quantity' => 1, 'unit_price' => 2_000_000, 'vat_rate' => 0],
        ]);

        $this->assertEquals(0, $invoice->tax_amount);

        $this->invoiceService->markSent($invoice);

        $taxCredit = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.reference_type', 'invoice')
            ->where('je.reference_id', $invoice->id)
            ->whereIn('je.status', ['draft', 'posted'])
            ->where('jl.account_code', '33311')
            ->sum('jl.credit');

        $this->assertEquals(0.0, (float) $taxCredit, 'Không có dòng Cr 33311 khi tất cả 0%');
    }
}
