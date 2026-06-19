<?php

namespace Tests\Feature\Purchasing;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashVoucherService;
use App\Services\PurchaseInvoiceService;
use App\Services\SupplierAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test: Hủy trả trước NCC + đảo JE CashVoucher
 *
 * TC1: Tạo trả trước → CashVoucher confirmed + JE Dr 331UT / Cr 1111
 * TC2: Hủy trả trước chưa allocation → CashVoucher cancelled + JE đảo + advance cancelled
 * TC3: Hủy trả trước đã có allocation active → throw RuntimeException
 * TC4: Hủy đối trừ → JE đảo Nợ 331UT / Có 3311, lưu reversal_entry_id
 * TC5: recallPayments với invoice có cả payment và allocation → status tính đúng
 * TC6: Reclass 3311 → 331UT qua RepairCenter → JE Nợ 331UT / Có 3311, không CashVoucher
 */
class SupplierAdvanceCancelTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAdvanceService $advanceService;
    private CashVoucherService $cashVoucherService;
    private PurchaseInvoiceService $invoiceService;
    private User $user;
    private Supplier $supplier;
    private Fund $fund;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->advanceService     = app(SupplierAdvanceService::class);
        $this->cashVoucherService = app(CashVoucherService::class);
        $this->invoiceService     = app(PurchaseInvoiceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Seed tài khoản cần thiết
        foreach ([
            ['331',   'Phải trả NCC',            'liability', 'credit', null,    2, false],
            ['3311',  'Phải trả NCC chi tiết',    'liability', 'credit', '331',  3, true],
            ['331UT', 'Trả trước cho người bán',  'asset',     'debit',  '331',  4, true],
            ['1111',  'Tiền mặt VND',             'asset',     'debit',  null,   2, true],
        ] as [$code, $name, $type, $nb, $parent, $level, $isDetail]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name'           => $name,
                'type'           => $type,
                'normal_balance' => $nb,
                'parent_code'    => $parent,
                'level'          => $level,
                'is_detail'      => $isDetail,
                'is_active'      => true,
            ]);
        }

        $this->fund = Fund::create([
            'code'         => 'QUY-TEST',
            'name'         => 'Quỹ tiền mặt test',
            'type'         => 'cash',
            'account_code' => '1111',
            'is_active'    => true,
        ]);

        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KHO-TEST']);

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-CANCEL-TEST',
            'name'                 => 'NCC Test Cancel',
            'phone'                => '0901234567',
            'payable_account_code' => '3311',
        ]);
    }

    // ─── Helper: Tạo prepayment advance + CashVoucher confirmed ──────────────

    private function makePrepaymentWithVoucher(float $amount = 5_000_000): array
    {
        $advance = $this->advanceService->create([
            'supplier_id'  => $this->supplier->id,
            'advance_type' => 'prepayment',
            'opening_date' => '2026-06-01',
            'account_code' => '331UT',
            'amount'       => $amount,
            'created_by'   => $this->user->id,
        ]);

        $voucher = CashVoucher::create([
            'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
            'type'           => CashVoucherType::Payment,
            'status'         => CashVoucherStatus::Draft,
            'fund_id'        => $this->fund->id,
            'supplier_id'    => $this->supplier->id,
            'partner_type'   => 'supplier',
            'amount'         => $amount,
            'voucher_date'   => '2026-06-01',
            'description'    => 'Trả trước NCC test',
            'business_type'  => 'supplier_prepayment',
            'reference_type' => SupplierOpeningAdvance::class,
            'reference_id'   => $advance->id,
            'created_by'     => $this->user->id,
        ]);

        $this->cashVoucherService->confirm($voucher);
        $voucher->refresh();

        return [$advance, $voucher];
    }

    private function makeInvoice(float $total = 10_000_000): PurchaseInvoice
    {
        $po = PurchaseOrder::create([
            'code'         => 'PO-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-06-01',
            'status'       => 'sent',
            'total'        => $total,
            'created_by'   => $this->user->id,
        ]);

        return PurchaseInvoice::create([
            'code'                     => 'HD-' . uniqid(),
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

    // ─── TC1: Tạo prepayment → CashVoucher confirmed + JE Dr 331UT / Cr 1111 ─

    public function test_tc1_create_prepayment_confirms_voucher_and_posts_je(): void
    {
        [$advance, $voucher] = $this->makePrepaymentWithVoucher(5_000_000);

        $this->assertEquals(CashVoucherStatus::Confirmed, $voucher->status);

        // JE phải tồn tại với Nợ 331UT / Có 1111
        $je = JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $voucher->id)
            ->where('status', 'posted')
            ->first();

        $this->assertNotNull($je, 'Phải có JE posted cho CashVoucher');

        $debitLine = $je->lines()->where('account_code', '331UT')->where('debit', '>', 0)->first();
        $this->assertNotNull($debitLine, 'JE phải có dòng Nợ 331UT');

        $creditLine = $je->lines()->where('account_code', '1111')->where('credit', '>', 0)->first();
        $this->assertNotNull($creditLine, 'JE phải có dòng Có 1111');

        $this->assertEquals('open', $advance->status);
        $this->assertEquals(5_000_000, (float) $advance->remaining_amount);
    }

    // ─── TC2: Hủy prepayment chưa allocation → cancel CashVoucher + đảo JE ──

    public function test_tc2_cancel_prepayment_reverses_voucher_and_je(): void
    {
        [$advance, $voucher] = $this->makePrepaymentWithVoucher(5_000_000);

        $originalJeId = JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $voucher->id)
            ->where('status', 'posted')
            ->value('id');
        $this->assertNotNull($originalJeId, 'JE gốc phải tồn tại trước khi hủy');

        // Hủy advance
        $this->advanceService->cancel($advance, 'Test cancel');

        $advance->refresh();
        $voucher->refresh();

        $this->assertEquals('cancelled', $advance->status, 'Advance phải cancelled');
        $this->assertEquals(CashVoucherStatus::Cancelled, $voucher->status, 'CashVoucher phải cancelled');

        // JE gốc phải bị đảo (reversed) hoặc deleted
        $originalJe = JournalEntry::find($originalJeId);
        if ($originalJe) {
            $this->assertNotEquals('posted', $originalJe->status, 'JE gốc không được còn status posted');
        }

        // Phải có JE đảo (Nợ 1111 / Có 331UT)
        $reversalJe = JournalEntry::where('status', 'posted')
            ->whereHas('lines', fn ($q) => $q->where('account_code', '1111')->where('debit', '>', 0))
            ->whereHas('lines', fn ($q) => $q->where('account_code', '331UT')->where('credit', '>', 0))
            ->latest('id')
            ->first();

        $this->assertNotNull($reversalJe, 'Phải có JE đảo Nợ 1111 / Có 331UT sau khi hủy');
    }

    // ─── TC3: Hủy prepayment đã allocation → throw exception ─────────────────

    public function test_tc3_cancel_with_active_allocation_throws(): void
    {
        [$advance] = $this->makePrepaymentWithVoucher(10_000_000);
        $invoice   = $this->makeInvoice(10_000_000);

        // Tạo allocation
        $this->advanceService->allocate($advance, $invoice, 5_000_000, '2026-06-15', 'test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đối trừ đang hoạt động/');

        $this->advanceService->cancel($advance, 'Cố tình hủy');
    }

    // ─── TC4: Hủy allocation → JE đảo Nợ 331UT / Có 3311 + lưu reversal_entry_id

    public function test_tc4_reverse_allocation_stores_reversal_entry_id(): void
    {
        [$advance] = $this->makePrepaymentWithVoucher(10_000_000);
        $invoice   = $this->makeInvoice(10_000_000);

        $allocation = $this->advanceService->allocate($advance, $invoice, 10_000_000, '2026-06-15', 'đối trừ');
        $this->assertNotNull($allocation->journal_entry_id, 'Allocation phải có JE gốc');

        // Đảo
        $this->advanceService->reverse($allocation, 'Hủy đối trừ test');

        $allocation->refresh();
        $this->assertEquals('reversed', $allocation->status);
        $this->assertNotNull($allocation->reversal_entry_id, 'Phải lưu reversal_entry_id');
        $this->assertEquals('Hủy đối trừ test', $allocation->reverse_reason);
        $this->assertEquals($this->user->id, $allocation->reversed_by);

        // JE đảo: Nợ 331UT / Có 3311
        $reversalJe = JournalEntry::find($allocation->reversal_entry_id);
        $this->assertNotNull($reversalJe, 'JE đảo phải tồn tại');

        $debitLine = $reversalJe->lines()->where('account_code', '331UT')->where('debit', '>', 0)->first();
        $this->assertNotNull($debitLine, 'JE đảo phải có dòng Nợ 331UT');

        $creditLine = $reversalJe->lines()->where('account_code', '3311')->where('credit', '>', 0)->first();
        $this->assertNotNull($creditLine, 'JE đảo phải có dòng Có 3311');

        // Advance remaining phải tăng lại
        $advance->refresh();
        $this->assertEquals(10_000_000, (float) $advance->remaining_amount);
        $this->assertEquals('open', $advance->status);

        // Invoice advance_allocated_amount phải về 0
        $invoice->refresh();
        $this->assertEquals(0.0, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::Valid, $invoice->status);
    }

    // ─── TC5: recallPayments với invoice có cả payment và allocation ──────────

    public function test_tc5_recall_payments_recalculates_with_allocation(): void
    {
        [$advance] = $this->makePrepaymentWithVoucher(3_000_000);
        $invoice   = $this->makeInvoice(10_000_000);

        // Allocation 3M
        $this->advanceService->allocate($advance, $invoice, 3_000_000, '2026-06-15', 'đối trừ');

        // Seed TK cần để addPayment tạo CashVoucher
        AccountCode::firstOrCreate(['code' => '3311'], [
            'name' => 'Phải trả NCC', 'type' => 'liability', 'normal_balance' => 'credit',
            'parent_code' => '331', 'level' => 3, 'is_detail' => true, 'is_active' => true,
        ]);

        // Thêm cash payment 4M qua service
        $this->invoiceService->addPayment($invoice, [
            'amount'       => 4_000_000,
            'fund_id'      => $this->fund->id,
            'payment_date' => '2026-06-16',
            'method'       => 'cash',
            'note'         => 'Thanh toán test',
        ]);

        $invoice->refresh();
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);
        $this->assertEquals(4_000_000, (float) $invoice->paid_amount);
        $this->assertEquals(3_000_000, (float) $invoice->advance_allocated_amount);

        // Recall chỉ cash payments
        $this->invoiceService->recallPayments($invoice, 'Thu hồi test');

        $invoice->refresh();
        // paid_amount về 0 (chỉ recall cash)
        $this->assertEquals(0.0, (float) $invoice->paid_amount);
        // advance_allocated_amount vẫn còn 3M → status PartialPaid (không phải Valid)
        $this->assertEquals(3_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status,
            'Status phải là partial_paid vì advance_allocated_amount vẫn còn');
    }

    // ─── TC6: Cancel prepayment không có CashVoucher → vẫn cancel được (opening_balance type)

    public function test_tc6_cancel_opening_balance_advance_no_voucher(): void
    {
        // Opening balance advance (không có CashVoucher)
        $advance = $this->advanceService->create([
            'supplier_id'  => $this->supplier->id,
            'advance_type' => 'opening_balance',
            'fiscal_year'  => 2026,
            'opening_date' => '2026-01-01',
            'account_code' => '331UT',
            'amount'       => 7_000_000,
            'created_by'   => $this->user->id,
        ]);

        $this->advanceService->cancel($advance, 'Hủy số dư đầu kỳ');

        $advance->refresh();
        $this->assertEquals('cancelled', $advance->status);
    }
}
