<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Fund;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests cho 3 payment modes: cash / offset / combined
 *
 * Case 1: payment_type=cash — tạo PC-, không tạo allocation
 * Case 2: payment_type=offset — tạo allocation, không tạo PC-/giảm quỹ
 * Case 3: payment_type=combined — tạo cả allocation + PC-
 * Case 4: advance khác NCC → từ chối
 * Case 5: tổng thanh toán vượt amount_due → từ chối
 * Case 6: advance đã dùng hết → từ chối
 * Case 7: createPrepayment() tạo advance_type='prepayment'
 * Case 8: nhiều advance phân bổ cho 1 hóa đơn
 */
class SupplierPrepaymentOffsetTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAdvanceService $advanceService;
    private User $user;
    private Supplier $supplier;
    private Supplier $otherSupplier;
    private Warehouse $warehouse;
    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->advanceService = app(SupplierAdvanceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Seed minimum account codes required by CashVoucherService JE
        foreach ([
            ['code' => '3311', 'name' => 'Phải trả NCC', 'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '1121', 'name' => 'Tiền gửi NH',   'type' => 'asset',     'normal_balance' => 'debit'],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['level' => 3, 'is_detail' => true]));
        }

        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KHO-PREPAY']);
        $this->supplier      = Supplier::create(['code' => 'NCC-PP-1', 'name' => 'NCC Prepay 1', 'phone' => '0901', 'payable_account_code' => '3311']);
        $this->otherSupplier = Supplier::create(['code' => 'NCC-PP-2', 'name' => 'NCC Prepay 2', 'phone' => '0902', 'payable_account_code' => '3311']);

        $this->fund = Fund::create([
            'code'         => 'QT-TEST',
            'name'         => 'Quỹ Test',
            'type'         => 'bank',
            'account_code' => '1121',
            'balance'      => 1_000_000_000,
            'is_active'    => true,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function makeAdvance(Supplier $supplier, float $amount, string $type = 'opening_balance'): SupplierOpeningAdvance
    {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $supplier->id,
            'advance_type'     => $type,
            'fiscal_year'      => 2026, // always set for SQLite compat (NOT NULL in test env)
            'opening_date'     => '2026-01-01',
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);
    }

    private function makeInvoice(Supplier $supplier, float $total): PurchaseInvoice
    {
        $po = PurchaseOrder::create([
            'code'         => 'PO-' . uniqid(),
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-06-01',
            'status'       => 'sent',
            'total'        => $total,
            'created_by'   => $this->user->id,
        ]);

        return PurchaseInvoice::create([
            'code'                     => 'MH-PP-' . uniqid(),
            'supplier_id'              => $supplier->id,
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

    // ─── Case 1: payment_type=cash ────────────────────────────────────────

    public function test_case1_cash_payment_creates_voucher_no_allocation(): void
    {
        $invoice = $this->makeInvoice($this->supplier, 50_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type' => 'cash',
                'amount'       => 30_000_000,
                'payment_date' => '2026-06-17',
                'method'       => 'bank_transfer',
                'fund_id'      => $this->fund->id,
                'reference'    => 'REF-001',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();
        $this->assertEquals(30_000_000, (float) $invoice->paid_amount);
        $this->assertEquals(0, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);

        // No advance allocation created
        $this->assertEquals(0, SupplierAdvanceAllocation::count());
    }

    // ─── Case 2: payment_type=offset — không tạo phiếu chi ───────────────

    public function test_case2_offset_only_no_cash_voucher_created(): void
    {
        $advance = $this->makeAdvance($this->supplier, 50_000_000);
        $invoice = $this->makeInvoice($this->supplier, 80_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type'         => 'offset',
                'allocation_date'      => '2026-06-17',
                'advance_allocations'  => [
                    ['advance_id' => $advance->id, 'amount' => 50_000_000],
                ],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();
        $advance->refresh();

        // Invoice: 50M offset, 30M still due
        $this->assertEquals(0, (float) $invoice->paid_amount);
        $this->assertEquals(50_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);

        // Advance fully used
        $this->assertEquals(0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);

        // No cash payment record
        $this->assertEquals(0, $invoice->payments()->where('status', 'active')->count());

        // One allocation record
        $this->assertEquals(1, SupplierAdvanceAllocation::where('status', 'active')->count());
    }

    // ─── Case 3: payment_type=combined — offset + chi thêm ───────────────

    public function test_case3_combined_offset_and_cash(): void
    {
        $advance = $this->makeAdvance($this->supplier, 30_000_000);
        $invoice = $this->makeInvoice($this->supplier, 100_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type'        => 'combined',
                'allocation_date'     => '2026-06-17',
                'advance_allocations' => [
                    ['advance_id' => $advance->id, 'amount' => 30_000_000],
                ],
                'amount'              => 70_000_000,
                'payment_date'        => '2026-06-17',
                'method'              => 'bank_transfer',
                'fund_id'             => $this->fund->id,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();
        $advance->refresh();

        // Invoice fully paid
        $this->assertEquals(70_000_000, (float) $invoice->paid_amount);
        $this->assertEquals(30_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::Paid, $invoice->status);
        $this->assertEquals(0, (float) $invoice->remaining);

        // Advance exhausted
        $this->assertEquals('fully_applied', $advance->status);

        // Both payment record and allocation created
        $this->assertEquals(1, $invoice->payments()->where('status', 'active')->count());
        $this->assertEquals(1, SupplierAdvanceAllocation::where('status', 'active')->count());
    }

    // ─── Case 4: advance của NCC khác → bị từ chối ───────────────────────

    public function test_case4_advance_from_different_supplier_rejected(): void
    {
        $advance = $this->makeAdvance($this->otherSupplier, 50_000_000);
        $invoice = $this->makeInvoice($this->supplier, 80_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type'        => 'offset',
                'allocation_date'     => '2026-06-17',
                'advance_allocations' => [
                    ['advance_id' => $advance->id, 'amount' => 50_000_000],
                ],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $invoice->refresh();
        $this->assertEquals(PurchaseInvoiceStatus::Valid, $invoice->status);
    }

    // ─── Case 5: tổng vượt quá amount_due → bị từ chối ──────────────────

    public function test_case5_total_exceeds_amount_due_rejected(): void
    {
        $advance = $this->makeAdvance($this->supplier, 100_000_000);
        $invoice = $this->makeInvoice($this->supplier, 50_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type'        => 'offset',
                'allocation_date'     => '2026-06-17',
                'advance_allocations' => [
                    ['advance_id' => $advance->id, 'amount' => 60_000_000],
                ],
            ]
        );

        $response->assertRedirect();
        // Either validation error or session error
        $hasError = $response->getSession()->has('error') || $response->getSession()->has('errors');
        $this->assertTrue($hasError);

        $invoice->refresh();
        $this->assertEquals(0, (float) $invoice->advance_allocated_amount);
    }

    // ─── Case 6: advance đã dùng hết → từ chối ───────────────────────────

    public function test_case6_fully_used_advance_rejected(): void
    {
        $advance = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice1 = $this->makeInvoice($this->supplier, 20_000_000);
        $invoice2 = $this->makeInvoice($this->supplier, 30_000_000);

        // Use up the advance on invoice1
        $this->advanceService->allocate($advance, $invoice1, 20_000_000, '2026-06-17', 'First');

        // Try to offset against invoice2 with exhausted advance
        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice2->id),
            [
                'payment_type'        => 'offset',
                'allocation_date'     => '2026-06-17',
                'advance_allocations' => [
                    ['advance_id' => $advance->id, 'amount' => 10_000_000],
                ],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $invoice2->refresh();
        $this->assertEquals(0, (float) $invoice2->advance_allocated_amount);
    }

    // ─── Case 7: createPrepayment() tạo đúng advance_type ────────────────

    public function test_case7_create_prepayment_advance(): void
    {
        $advance = $this->advanceService->createPrepayment(
            supplierId: $this->supplier->id,
            amount:     30_000_000,
            date:       '2026-06-10',
            reference:  'PC-0023',
            notes:      'Ứng trước đợt 1',
            sourceType: 'cash_voucher',
            sourceId:   99,
        );

        $this->assertEquals('prepayment', $advance->advance_type);
        $this->assertEquals('cash_voucher', $advance->source_type);
        $this->assertEquals(99, $advance->source_id);
        $this->assertEquals(30_000_000, (float) $advance->amount);
        $this->assertEquals(30_000_000, (float) $advance->remaining_amount);
        $this->assertEquals('open', $advance->status);
    }

    // ─── Case 8: 2 advances phân bổ cho 1 hóa đơn ────────────────────────

    public function test_case8_multiple_advances_allocated_to_one_invoice(): void
    {
        $adv1 = $this->makeAdvance($this->supplier, 30_000_000, 'opening_balance');
        $adv2 = $this->makeAdvance($this->supplier, 20_000_000, 'prepayment');
        $invoice = $this->makeInvoice($this->supplier, 100_000_000);

        $response = $this->post(
            route('purchasing.purchase-invoices.payments.store', $invoice->id),
            [
                'payment_type'        => 'offset',
                'allocation_date'     => '2026-06-17',
                'advance_allocations' => [
                    ['advance_id' => $adv1->id, 'amount' => 30_000_000],
                    ['advance_id' => $adv2->id, 'amount' => 20_000_000],
                ],
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $invoice->refresh();
        $this->assertEquals(50_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);

        $adv1->refresh();
        $adv2->refresh();
        $this->assertEquals(0, (float) $adv1->remaining_amount);
        $this->assertEquals(0, (float) $adv2->remaining_amount);
        $this->assertEquals(2, SupplierAdvanceAllocation::where('status', 'active')->count());
    }
}
