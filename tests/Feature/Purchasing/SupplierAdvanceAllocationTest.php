<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\AccountingPeriod;
use App\Models\ArApOpeningBalance;
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
 * VII. Test bắt buộc — đối trừ ứng trước đầu kỳ NCC
 *
 * TC1: ứng trước 20M, hóa đơn 10.8M → đối trừ toàn bộ HĐ, ứng trước còn 9.2M, không tạo bút toán CK
 * TC2: ứng trước 20M, hóa đơn 30M → đối trừ 20M, HĐ còn 10M, ứng trước = 0
 * TC3: NCC không có ứng trước → getAvailable() = rỗng
 * TC4: một NCC nhiều hóa đơn → phân bổ tuần tự, không vượt ứng trước
 * TC5: hóa đơn đã đối trừ → không cho đối trừ trùng (vượt quá remaining)
 * TC6: thu hồi đối trừ → hoàn lại ứng trước, hóa đơn về valid
 */
class SupplierAdvanceAllocationTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAdvanceService $service;
    private User $user;
    private Supplier $supplier;
    private Supplier $otherSupplier;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SupplierAdvanceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KHO-TEST']);

        $this->supplier = Supplier::create([
            'code'  => 'NCC-TEST-1',
            'name'  => 'NCC Test 1',
            'phone' => '0901234567',
        ]);

        $this->otherSupplier = Supplier::create([
            'code'  => 'NCC-TEST-2',
            'name'  => 'NCC Test 2',
            'phone' => '0909999999',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function makeAdvance(Supplier $supplier, float $amount, int $year = 2026): SupplierOpeningAdvance
    {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $supplier->id,
            'fiscal_year'      => $year,
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
            'order_date'   => '2026-01-15',
            'status'       => 'sent',
            'total'        => $total,
            'created_by'   => $this->user->id,
        ]);

        return PurchaseInvoice::create([
            'code'        => 'HD-NCC-' . uniqid(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'subtotal'    => $total / 1.08,
            'tax_amount'  => $total - ($total / 1.08),
            'total'       => $total,
            'paid_amount' => 0,
            'advance_allocated_amount' => 0,
            'status'      => PurchaseInvoiceStatus::Valid,
            'created_by'  => $this->user->id,
        ]);
    }

    // ─── TC1: ứng trước 20M, HĐ 10.8M ───────────────────────────────────

    public function test_tc1_advance_20m_invoice_10_8m_fully_offset(): void
    {
        $advance = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice = $this->makeInvoice($this->supplier, 10_800_000);

        $allocation = $this->service->allocate(
            $advance, $invoice, 10_800_000, '2026-04-08', 'Đối trừ ứng trước'
        );

        $advance->refresh();
        $invoice->refresh();

        // HĐ phải trả = 0
        $this->assertEquals(10_800_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(0.0, $invoice->amountDue());
        $this->assertEquals(PurchaseInvoiceStatus::Paid, $invoice->status);

        // Ứng trước còn 9.2M
        $this->assertEquals(9_200_000, (float) $advance->remaining_amount);
        $this->assertEquals('partially_applied', $advance->status);

        // Không tạo JE (allocation record only)
        $this->assertDatabaseHas('supplier_advance_allocations', [
            'id'               => $allocation->id,
            'allocated_amount' => '10800000.00',
            'status'           => 'active',
        ]);
    }

    // ─── TC2: ứng trước 20M, HĐ 30M ─────────────────────────────────────

    public function test_tc2_advance_20m_invoice_30m_partial_offset(): void
    {
        $advance = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice = $this->makeInvoice($this->supplier, 30_000_000);

        $this->service->allocate(
            $advance, $invoice, 20_000_000, '2026-04-08'
        );

        $advance->refresh();
        $invoice->refresh();

        // Đã đối trừ: 20M, còn phải trả: 10M
        $this->assertEquals(20_000_000, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(10_000_000, $invoice->amountDue());
        $this->assertEquals(PurchaseInvoiceStatus::PartialPaid, $invoice->status);

        // Ứng trước = 0
        $this->assertEquals(0.0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);
    }

    // ─── TC3: NCC không có ứng trước ─────────────────────────────────────

    public function test_tc3_supplier_without_advance_returns_empty(): void
    {
        // Tạo ứng trước cho NCC khác, không phải NCC này
        $this->makeAdvance($this->otherSupplier, 5_000_000);

        $available = $this->service->getAvailable($this->supplier->id);
        $this->assertCount(0, $available);
        $this->assertEquals(0.0, $this->service->totalAvailable($this->supplier->id));
    }

    // ─── TC4: nhiều hóa đơn, phân bổ tuần tự ────────────────────────────

    public function test_tc4_multiple_invoices_sequential_allocation(): void
    {
        $advance  = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice1 = $this->makeInvoice($this->supplier, 8_000_000);
        $invoice2 = $this->makeInvoice($this->supplier, 15_000_000);

        // Đối trừ HĐ1 toàn bộ
        $this->service->allocate($advance, $invoice1, 8_000_000, '2026-04-08');

        $advance->refresh();
        $this->assertEquals(12_000_000, (float) $advance->remaining_amount);
        $this->assertEquals('partially_applied', $advance->status);

        // Đối trừ HĐ2 bằng số còn lại của ứng trước (12M)
        $this->service->allocate($advance, $invoice2, 12_000_000, '2026-04-10');

        $advance->refresh();
        $invoice2->refresh();

        $this->assertEquals(0.0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);
        $this->assertEquals(3_000_000, $invoice2->amountDue()); // 15M - 12M = 3M còn lại

        // Không thể đối trừ thêm (ứng trước hết)
        $this->expectException(\RuntimeException::class);
        $this->service->allocate($advance, $invoice2, 1_000, '2026-04-10');
    }

    // ─── TC5: không cho đối trừ vượt remaining ───────────────────────────

    public function test_tc5_cannot_over_allocate(): void
    {
        $advance = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice = $this->makeInvoice($this->supplier, 10_000_000);

        // Đối trừ toàn bộ HĐ
        $this->service->allocate($advance, $invoice, 10_000_000, '2026-04-08');

        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->amountDue());

        // Cố đối trừ thêm → hóa đơn đã thanh toán hết
        $this->expectException(\RuntimeException::class);
        $this->service->allocate($advance, $invoice, 1_000, '2026-04-08');
    }

    // ─── TC6: thu hồi đối trừ → hoàn lại trạng thái ─────────────────────

    public function test_tc6_reverse_allocation_restores_state(): void
    {
        $advance = $this->makeAdvance($this->supplier, 20_000_000);
        $invoice = $this->makeInvoice($this->supplier, 10_800_000);

        $allocation = $this->service->allocate(
            $advance, $invoice, 10_800_000, '2026-04-08'
        );

        // Sau đối trừ: HĐ = Paid, ứng trước = 9.2M
        $invoice->refresh();
        $this->assertEquals(PurchaseInvoiceStatus::Paid, $invoice->status);

        // Thu hồi
        $this->service->reverse($allocation, 'Sửa lại số tiền');

        $advance->refresh();
        $invoice->refresh();

        // HĐ trở về Valid, còn phải trả 10.8M
        $this->assertEquals(PurchaseInvoiceStatus::Valid, $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->advance_allocated_amount);
        $this->assertEquals(10_800_000, $invoice->amountDue());

        // Ứng trước hoàn lại 20M
        $this->assertEquals(20_000_000, (float) $advance->remaining_amount);
        $this->assertEquals('open', $advance->status);

        // Allocation record = reversed
        $allocation->refresh();
        $this->assertEquals('reversed', $allocation->status);

        // Không thể thu hồi lần nữa
        $this->expectException(\RuntimeException::class);
        $this->service->reverse($allocation, 'Lần 2');
    }

    // ─── Helpers cho opening balance ─────────────────────────────────────

    private function makeOpeningBalance(Supplier $supplier, float $amount): ArApOpeningBalance
    {
        return ArApOpeningBalance::create([
            'type'             => 'ap',
            'period'           => '2026-01',
            'supplier_id'      => $supplier->id,
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'created_by'       => $this->user->id,
        ]);
    }

    // ─── TC7: đối trừ advance vào opening balance AP ─────────────────────

    public function test_tc7_advance_offset_opening_balance_fully(): void
    {
        $advance = $this->makeAdvance($this->supplier, 5_000_000);
        $ob      = $this->makeOpeningBalance($this->supplier, 5_000_000);

        $allocation = $this->service->allocateToOpeningBalance(
            $advance, $ob, 5_000_000, '2026-06-18', 'Đối trừ CN ĐK'
        );

        $advance->refresh();
        $ob->refresh();

        // Opening balance = 0
        $this->assertEquals(0.0, (float) $ob->remaining_amount);

        // Ứng trước hết
        $this->assertEquals(0.0, (float) $advance->remaining_amount);
        $this->assertEquals('fully_applied', $advance->status);

        // Allocation record đúng
        $this->assertDatabaseHas('supplier_advance_allocations', [
            'id'                       => $allocation->id,
            'ar_ap_opening_balance_id' => $ob->id,
            'purchase_invoice_id'      => null,
            'status'                   => 'active',
        ]);
    }

    // ─── TC8: không cho đối trừ advance của NCC khác vào opening balance ──

    public function test_tc8_cannot_offset_other_supplier_advance_to_opening_balance(): void
    {
        $advance = $this->makeAdvance($this->otherSupplier, 5_000_000);
        $ob      = $this->makeOpeningBalance($this->supplier, 5_000_000);

        $this->expectException(\RuntimeException::class);
        $this->service->allocateToOpeningBalance($advance, $ob, 5_000_000, '2026-06-18');
    }

    // ─── TC9: không cho đối trừ vượt advance remaining ──────────────────

    public function test_tc9_cannot_over_allocate_to_opening_balance(): void
    {
        $advance = $this->makeAdvance($this->supplier, 3_000_000);
        $ob      = $this->makeOpeningBalance($this->supplier, 5_000_000);

        $this->expectException(\RuntimeException::class);
        $this->service->allocateToOpeningBalance($advance, $ob, 4_000_000, '2026-06-18');
    }

    // ─── TC10: thu hồi đối trừ opening balance → hoàn lại cả hai ────────

    public function test_tc10_reverse_opening_balance_allocation_restores_both(): void
    {
        $advance = $this->makeAdvance($this->supplier, 10_000_000);
        $ob      = $this->makeOpeningBalance($this->supplier, 8_000_000);

        $allocation = $this->service->allocateToOpeningBalance(
            $advance, $ob, 8_000_000, '2026-06-18', 'Đối trừ ĐK'
        );

        // Sau đối trừ
        $advance->refresh();
        $ob->refresh();
        $this->assertEquals(2_000_000, (float) $advance->remaining_amount);
        $this->assertEquals(0.0, (float) $ob->remaining_amount);

        // Thu hồi
        $this->service->reverse($allocation, 'Thu hồi test');

        $advance->refresh();
        $ob->refresh();

        // Cả hai hoàn lại
        $this->assertEquals(10_000_000, (float) $advance->remaining_amount);
        $this->assertEquals(8_000_000, (float) $ob->remaining_amount);
        $this->assertEquals('open', $advance->status);

        $allocation->refresh();
        $this->assertEquals('reversed', $allocation->status);
    }
}
