<?php

namespace Tests\Feature\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\CustomerOpeningAdvance;
use App\Models\Fund;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerAdvanceService;
use App\Services\SupplierAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 9 test cases theo spec công nợ ứng trước/trả trước (section 14)
 *
 * Case 1: KH ứng trước → Dr 1121 / Cr 131UT; không phát sinh 1311
 * Case 2: Đối trừ ứng trước KH vào HĐ → Dr 131UT / Cr 1311; HĐ còn 30M
 * Case 3: Kết hợp đối trừ 50M + thu thêm 30M → HĐ paid
 * Case 4: Trả trước NCC → Dr 331UT / Cr 1121; không phát sinh 3311
 * Case 5: Đối trừ trả trước NCC vào HĐMH → Dr 3311 / Cr 331UT; HĐMH còn 60M
 * Case 6: Kết hợp đối trừ 40M + chi thêm 60M → HĐMH paid
 * Case 7: Số dư ĐK âm → AR âm → Cr 131UT; AP âm → Dr 331UT; không ghi âm vào 1311/3311
 * Case 8: getAvailable() chỉ trả ứng trước của đúng KH
 * Case 9: JE đối trừ không có TK 111x/112x (không tạo dòng tiền)
 */
class CustomerAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAdvanceService $arService;
    private SupplierAdvanceService $apService;
    private User $user;
    private Customer $customer;
    private Customer $otherCustomer;
    private Supplier $supplier;
    private Fund $fund;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arService = app(CustomerAdvanceService::class);
        $this->apService = app(SupplierAdvanceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        foreach ([
            ['code' => '1121',  'name' => 'TG Ngân hàng',        'type' => 'asset',     'normal_balance' => 'debit'],
            ['code' => '1311',  'name' => 'Phải thu KH',         'type' => 'asset',     'normal_balance' => 'debit'],
            ['code' => '131UT', 'name' => 'KH ứng trước',        'type' => 'asset',     'normal_balance' => 'credit'],
            ['code' => '3311',  'name' => 'Phải trả NCC',        'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '331UT', 'name' => 'Trả trước NCC',       'type' => 'liability', 'normal_balance' => 'debit'],
            ['code' => '4111',  'name' => 'Vốn góp chủ sở hữu', 'type' => 'equity',    'normal_balance' => 'credit'],
        ] as $ac) {
            AccountCode::firstOrCreate(
                ['code' => $ac['code']],
                array_merge($ac, ['level' => 3, 'is_detail' => true])
            );
        }

        $this->warehouse = Warehouse::create(['name' => 'Kho ADV', 'code' => 'KHO-ADV']);

        $this->customer = Customer::create([
            'code'  => 'KH-ADV-1',
            'name'  => 'KH Advance 1',
            'phone' => '0901',
            'receivable_account_code' => '1311',
        ]);
        $this->otherCustomer = Customer::create([
            'code'  => 'KH-ADV-2',
            'name'  => 'KH Advance 2',
            'phone' => '0902',
            'receivable_account_code' => '1311',
        ]);
        $this->supplier = Supplier::create([
            'code'  => 'NCC-ADV-1',
            'name'  => 'NCC Advance 1',
            'phone' => '0903',
            'payable_account_code' => '3311',
        ]);

        $this->fund = Fund::create([
            'code'         => 'QT-ADV',
            'name'         => 'Quỹ Advance Test',
            'type'         => 'bank',
            'account_code' => '1121',
            'balance'      => 1_000_000_000,
            'is_active'    => true,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeCustomerAdvance(float $amount, string $type = 'opening_balance'): CustomerOpeningAdvance
    {
        return CustomerOpeningAdvance::create([
            'customer_id'      => $this->customer->id,
            'advance_type'     => $type,
            'advance_date'     => '2026-06-01',
            'account_code'     => '131UT',
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);
    }

    private function makeInvoice(float $total): Invoice
    {
        return Invoice::create([
            'code'                     => 'HĐ-ADV-' . uniqid(),
            'customer_id'              => $this->customer->id,
            'issue_date'               => '2026-06-01',
            'total'                    => $total,
            'amount_due'               => $total,
            'advance_allocated_amount' => 0,
            'status'                   => InvoiceStatus::Sent,
            'created_by'               => $this->user->id,
        ]);
    }

    private function makeSupplierAdvance(float $amount, string $type = 'opening_balance'): SupplierOpeningAdvance
    {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $this->supplier->id,
            'advance_type'     => $type,
            'account_code'     => '331UT',
            'fiscal_year'      => 2026,
            'opening_date'     => '2026-06-01',
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);
    }

    private function makePurchaseInvoice(float $total): PurchaseInvoice
    {
        $po = PurchaseOrder::create([
            'code'         => 'PO-ADV-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-06-01',
            'status'       => 'sent',
            'total'        => $total,
            'created_by'   => $this->user->id,
        ]);

        return PurchaseInvoice::create([
            'code'                     => 'HĐMH-ADV-' . uniqid(),
            'supplier_id'              => $this->supplier->id,
            'purchase_order_id'        => $po->id,
            'subtotal'                 => round($total / 1.1, 2),
            'tax_amount'               => round($total - $total / 1.1, 2),
            'total'                    => $total,
            'paid_amount'              => 0,
            'advance_allocated_amount' => 0,
            'status'                   => PurchaseInvoiceStatus::Valid,
            'created_by'               => $this->user->id,
        ]);
    }

    // ─── Case 1: KH ứng trước → Dr 1121 / Cr 131UT ───────────────────────

    public function test_case1_customer_advance_receipt_creates_je_dr1121_cr131ut(): void
    {
        $response = $this->post(route('sales.customer-advances.store'), [
            'customer_id'    => $this->customer->id,
            'advance_type'   => 'advance_receipt',
            'advance_date'   => '2026-06-01',
            'amount'         => 50_000_000,
            'fund_id'        => $this->fund->id,
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // CustomerOpeningAdvance được tạo với TK 131UT
        $advance = CustomerOpeningAdvance::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($advance);
        $this->assertEquals('advance_receipt', $advance->advance_type);
        $this->assertEquals(50_000_000, (float) $advance->amount);
        $this->assertEquals('131UT', $advance->account_code);
        $this->assertEquals('open', $advance->status);

        // Khả dụng để đối trừ về sau
        $available = $this->arService->getAvailable($this->customer->id);
        $this->assertCount(1, $available);

        // JE: Dr 1121 / Cr 131UT — không phát sinh 1311
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '1121']);
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '131UT']);
        $this->assertDatabaseMissing('journal_entry_lines', ['account_code' => '1311']);
    }

    // ─── Case 2: Đối trừ ứng trước KH → Dr 131UT / Cr 1311 ──────────────

    public function test_case2_customer_advance_offset_je_and_invoice_remaining(): void
    {
        $advance = $this->makeCustomerAdvance(50_000_000);
        $invoice = $this->makeInvoice(80_000_000);

        $allocation = $this->arService->allocate(
            $advance, $invoice, 50_000_000, '2026-06-10', 'Đối trừ ứng trước'
        );

        $advance->refresh();
        $invoice->refresh();

        // Ứng trước đã dùng hết
        $this->assertEquals(0.0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);

        // HĐ: advance_allocated=50M, còn phải thu 30M, chưa Paid
        $this->assertEquals(50_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(30_000_000, $invoice->amountDue());
        $this->assertNotEquals(InvoiceStatus::Paid, $invoice->status);

        // JE: Dr 131UT / Cr 1311
        $jeId = $allocation->journal_entry_id;
        $this->assertNotNull($jeId, 'Allocation phải có journal_entry_id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $jeId,
            'account_code'     => '131UT',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $jeId,
            'account_code'     => '1311',
        ]);
    }

    // ─── Case 3: Kết hợp đối trừ 50M + thu thêm 30M → HĐ paid ──────────

    public function test_case3_combined_advance_offset_and_cash_receipt_fully_pays(): void
    {
        $advance = $this->makeCustomerAdvance(50_000_000);
        $invoice = $this->makeInvoice(80_000_000);

        // Bước 1: đối trừ 50M
        $this->arService->allocate($advance, $invoice, 50_000_000, '2026-06-10');
        $invoice->refresh();
        $this->assertEquals(30_000_000, $invoice->amountDue());

        // Bước 2: thu thêm 30M
        $response = $this->post(route('accounting.invoices.payments.store', $invoice->id), [
            'amount'       => 30_000_000,
            'payment_date' => '2026-06-11',
            'method'       => 'bank_transfer',
            'fund_id'      => $this->fund->id,
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();

        // HĐ đã thanh toán đủ
        $this->assertEquals(50_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(0.0, $invoice->amountDue());
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
    }

    // ─── Case 4: Trả trước NCC → Dr 331UT / Cr 1121 ─────────────────────

    public function test_case4_supplier_prepayment_creates_je_dr331ut_cr1121(): void
    {
        $response = $this->post(route('purchasing.supplier-advances.store'), [
            'supplier_id'    => $this->supplier->id,
            'advance_type'   => 'prepayment',
            'opening_date'   => '2026-06-01',
            'amount'         => 40_000_000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $advance = SupplierOpeningAdvance::where('supplier_id', $this->supplier->id)->first();
        $this->assertNotNull($advance);
        $this->assertEquals('prepayment', $advance->advance_type);
        $this->assertEquals(40_000_000, (float) $advance->amount);
        $this->assertEquals('331UT', $advance->account_code);
        $this->assertEquals('unpaid', $advance->status);

        // Thực hiện Xác nhận thanh toán qua API
        $payResponse = $this->post(route('purchasing.supplier-advances.pay', $advance->id), [
            'payment_date'   => '2026-06-01',
            'fund_id'        => $this->fund->id,
            'payment_method' => 'bank_transfer',
        ]);
        $payResponse->assertRedirect();

        $advance->refresh();
        $this->assertEquals('open', $advance->status);

        // JE: Dr 331UT / Cr 1121 — không phát sinh 3311
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '331UT']);
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '1121']);
        $this->assertDatabaseMissing('journal_entry_lines', ['account_code' => '3311']);
    }

    // ─── Case 5: Đối trừ trả trước NCC → Dr 3311 / Cr 331UT ─────────────

    public function test_case5_supplier_prepayment_offset_je_and_invoice_remaining(): void
    {
        $advance = $this->makeSupplierAdvance(40_000_000, 'prepayment');
        $invoice = $this->makePurchaseInvoice(100_000_000);

        $allocation = $this->apService->allocate(
            $advance, $invoice, 40_000_000, '2026-06-10'
        );

        $advance->refresh();
        $invoice->refresh();

        // Ứng trước hết
        $this->assertEquals(0.0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);

        // HĐMH: advance_allocated=40M, còn phải trả 60M
        $this->assertEquals(40_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(60_000_000, $invoice->amountDue());
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);

        // JE: Dr 3311 / Cr 331UT
        $jeId = $allocation->journal_entry_id;
        $this->assertNotNull($jeId, 'Allocation prepayment phải có journal_entry_id');
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $jeId,
            'account_code'     => '3311',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $jeId,
            'account_code'     => '331UT',
        ]);
    }

    // ─── Case 6: Kết hợp đối trừ 40M + chi thêm 60M → HĐMH paid ─────────

    public function test_case6_combined_supplier_offset_and_cash_payment_fully_pays(): void
    {
        $advance = $this->makeSupplierAdvance(40_000_000, 'prepayment');
        $invoice = $this->makePurchaseInvoice(100_000_000);

        // Bước 1: đối trừ 40M
        $this->apService->allocate($advance, $invoice, 40_000_000, '2026-06-10');
        $invoice->refresh();
        $this->assertEquals(60_000_000, $invoice->amountDue());

        // Bước 2: chi thêm 60M
        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type' => 'cash',
                'amount'       => 60_000_000,
                'payment_date' => '2026-06-11',
                'method'       => 'bank_transfer',
                'fund_id'      => $this->fund->id,
            ]
        );
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();

        $this->assertEquals(40_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(0.0, $invoice->amountDue());
        $this->assertEquals(PurchaseInvoiceStatus::Paid, $invoice->status);
    }

    // ─── Case 7: Số dư ĐK âm → AR âm → Cr 131UT; AP âm → Dr 331UT ──────

    public function test_case7_negative_opening_balance_uses_ut_accounts(): void
    {
        // AR âm: KH ứng trước 20M → Cr 131UT (không ghi âm vào 1311)
        $arRes = $this->post(route('accounting.ar-ap-opening-balance.store'), [
            'type'   => 'ar',
            'period' => '2026-01',
            'items'  => [[
                'customer_id'      => $this->customer->id,
                'amount'           => -20_000_000,
                'remaining_amount' => -20_000_000,
            ]],
        ]);
        $arRes->assertRedirect();
        $arRes->assertSessionMissing('error');

        // JE phải có Cr 131UT
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '131UT']);

        // Không có Cr 1311 âm (credit > 0 trên TK 1311)
        $ar1311CrLines = JournalEntryLine::where('account_code', '1311')
            ->where('credit', '>', 0)->count();
        $this->assertEquals(0, $ar1311CrLines, 'Không được Cr 1311 khi AR âm');

        // AP âm: NCC trả trước 50M → Dr 331UT (không ghi âm vào 3311)
        $apRes = $this->post(route('accounting.ar-ap-opening-balance.store'), [
            'type'   => 'ap',
            'period' => '2026-01',
            'items'  => [[
                'supplier_id'      => $this->supplier->id,
                'amount'           => -50_000_000,
                'remaining_amount' => -50_000_000,
            ]],
        ]);
        $apRes->assertRedirect();
        $apRes->assertSessionMissing('error');

        // JE phải có Dr 331UT
        $this->assertDatabaseHas('journal_entry_lines', ['account_code' => '331UT']);

        // Không có Dr 3311 âm (debit > 0 trên TK 3311)
        $ap3311DrLines = JournalEntryLine::where('account_code', '3311')
            ->where('debit', '>', 0)->count();
        $this->assertEquals(0, $ap3311DrLines, 'Không được Dr 3311 khi AP âm');
    }

    // ─── Case 8: getAvailable() chỉ trả đúng KH ─────────────────────────

    public function test_case8_get_available_returns_only_matching_customer(): void
    {
        // Ứng trước của KH đúng
        $myAdvance = $this->makeCustomerAdvance(30_000_000);

        // Ứng trước của KH khác (không được trả về)
        CustomerOpeningAdvance::create([
            'customer_id'      => $this->otherCustomer->id,
            'advance_type'     => 'opening_balance',
            'advance_date'     => '2026-06-01',
            'account_code'     => '131UT',
            'amount'           => 10_000_000,
            'remaining_amount' => 10_000_000,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);

        $available = $this->arService->getAvailable($this->customer->id);
        $this->assertCount(1, $available);
        $this->assertEquals($myAdvance->id, $available->first()->id);
        $this->assertEquals(30_000_000, $this->arService->totalAvailable($this->customer->id));

        // KH khác không thấy advance của mình
        $this->assertEquals(0.0, $this->arService->totalAvailable(99999));
    }

    // ─── Case 9: JE đối trừ không có TK tiền (không tạo dòng tiền) ───────

    public function test_case9_offset_je_has_no_cash_or_bank_accounts(): void
    {
        $advance = $this->makeCustomerAdvance(50_000_000);
        $invoice = $this->makeInvoice(80_000_000);

        $allocation = $this->arService->allocate(
            $advance, $invoice, 50_000_000, '2026-06-10'
        );

        $jeId = $allocation->journal_entry_id;
        $this->assertNotNull($jeId);

        $lines = JournalEntryLine::where('journal_entry_id', $jeId)->get();
        $this->assertNotEmpty($lines, 'JE phải có dòng bút toán');

        // Không có TK 111x (tiền mặt) hoặc 112x (ngân hàng)
        $hasCash = $lines->contains(
            fn ($l) => str_starts_with($l->account_code, '111')
                    || str_starts_with($l->account_code, '112')
        );
        $this->assertFalse($hasCash, 'JE đối trừ không được có TK tiền mặt/ngân hàng');

        // Chỉ có 131UT (Dr) và 1311 (Cr)
        $codes = $lines->pluck('account_code')->unique()->sort()->values()->toArray();
        $this->assertEquals(['1311', '131UT'], $codes);
    }
}
