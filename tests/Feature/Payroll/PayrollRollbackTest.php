<?php

namespace Tests\Feature\Payroll;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\AccountingPeriod;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\PayrollRollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test hủy thanh toán lương (Payroll Rollback).
 *
 * TC1: payment_only — items → pending, payroll → confirmed, vouchers → cancelled
 * TC2: payment_and_accrual — items → pending, payroll → draft, draft accrual JE deleted
 * TC3: no paid items → throws RuntimeException
 * TC4: preview returns correct fields
 * TC5: rollback clears cash_voucher_id + salary_journal_entry_id
 * TC6: rollback with no cashVoucher link (safety: no crash)
 */
class PayrollRollbackTest extends TestCase
{
    use RefreshDatabase;

    private PayrollRollbackService $service;
    private User $user;
    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PayrollRollbackService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => now()->year, 'month' => now()->month, 'status' => 'open']);

        $this->fund = Fund::create([
            'code'         => 'QUY-TEST',
            'name'         => 'Quỹ Test',
            'type'         => 'cash',
            'account_code' => '1111',
            'balance'      => 100_000_000,
            'is_active'    => true,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makePayroll(string $status = 'paid'): Payroll
    {
        return Payroll::create([
            'code'              => 'BL-2026-06',
            'period'            => '2026-06',
            'status'            => PayrollStatus::from($status),
            'total_net_salary'  => 30_000_000,
            'total_base_salary' => 0,
            'total_allowance'   => 0,
            'total_bonus'       => 0,
            'created_by'        => $this->user->id,
        ]);
    }

    private function makePaidItem(Payroll $payroll, float $net = 10_000_000): PayrollItem
    {
        $voucher = CashVoucher::create([
            'code'           => 'PC-' . uniqid(),
            'type'           => CashVoucherType::Payment,
            'status'         => CashVoucherStatus::Confirmed,
            'fund_id'        => $this->fund->id,
            'amount'         => $net,
            'voucher_date'   => now()->toDateString(),
            'description'    => 'Test',
            'business_type'  => 'salary_payment',
            'partner_type'   => 'employee',
            'created_by'     => $this->user->id,
        ]);

        return PayrollItem::create([
            'payroll_id'              => $payroll->id,
            'net_salary'              => $net,
            'base_salary'             => 0,
            'allowance'               => 0,
            'bonus'                   => 0,
            'gross_salary'            => 0,
            'insurance_base'          => 0,
            'bhxh_employee'           => 0,
            'bhyt_employee'           => 0,
            'bhtn_employee'           => 0,
            'bhxh_employer'           => 0,
            'bhyt_employer'           => 0,
            'bhtn_employer'           => 0,
            'pit'                     => 0,
            'deductions'              => 0,
            'dependents_count'        => 0,
            'working_days'            => 26,
            'standard_days'           => 26,
            'advance'                 => 0,
            'insurance_subject'       => false,
            'status'                  => PayrollItemStatus::Paid,
            'paid_at'                 => now(),
            'cash_voucher_id'         => $voucher->id,
            'salary_journal_entry_id' => null,
        ]);
    }

    private function makePendingItem(Payroll $payroll): PayrollItem
    {
        return PayrollItem::create([
            'payroll_id'       => $payroll->id,
            'net_salary'       => 5_000_000,
            'base_salary'      => 0,
            'allowance'        => 0,
            'bonus'            => 0,
            'gross_salary'     => 0,
            'insurance_base'   => 0,
            'bhxh_employee'    => 0,
            'bhyt_employee'    => 0,
            'bhtn_employee'    => 0,
            'bhxh_employer'    => 0,
            'bhyt_employer'    => 0,
            'bhtn_employer'    => 0,
            'pit'              => 0,
            'deductions'       => 0,
            'dependents_count' => 0,
            'working_days'     => 26,
            'standard_days'    => 26,
            'advance'          => 0,
            'insurance_subject' => false,
            'status'           => PayrollItemStatus::Pending,
        ]);
    }

    // ─── TC1: payment_only ────────────────────────────────────────────────────

    public function test_tc1_payment_only_resets_items_and_payroll_to_confirmed(): void
    {
        $payroll = $this->makePayroll('paid');
        $item1   = $this->makePaidItem($payroll, 15_000_000);
        $item2   = $this->makePaidItem($payroll, 10_000_000);

        $this->service->rollback($payroll, 'payment_only', 'Chi nhầm quỹ');

        $payroll->refresh();
        $item1->refresh();
        $item2->refresh();

        // Bảng lương về confirmed
        $this->assertEquals(PayrollStatus::Confirmed, $payroll->status);

        // Dòng lương về pending
        $this->assertEquals(PayrollItemStatus::Pending, $item1->status);
        $this->assertNull($item1->paid_at);
        $this->assertNull($item1->cash_voucher_id);

        $this->assertEquals(PayrollItemStatus::Pending, $item2->status);

        // Phiếu chi bị hủy
        $voucher1 = CashVoucher::find($item1->getOriginal('cash_voucher_id') ?? CashVoucher::where('amount', 15_000_000)->first()->id);
        // Verify via DB check
        $this->assertDatabaseMissing('cash_vouchers', [
            'status' => CashVoucherStatus::Confirmed->value,
            'amount' => 15_000_000,
        ]);
    }

    // ─── TC2: payment_and_accrual — payroll → draft, draft JE deleted ────────

    public function test_tc2_payment_and_accrual_resets_to_draft(): void
    {
        $payroll = $this->makePayroll('confirmed');
        $item    = $this->makePaidItem($payroll, 10_000_000);

        // Tạo bút toán lương (draft, sẽ bị xóa bởi reverseOrDelete)
        $je = JournalEntry::create([
            'code'           => 'PKT-TEST-01',
            'entry_date'     => now()->toDateString(),
            'description'    => "Bảng lương tháng 2026-06 ({$payroll->code})",
            'status'         => 'draft',
            'is_auto'        => false,
            'reference_type' => 'payroll',
            'reference_id'   => $payroll->id,
            'created_by'     => $this->user->id,
            'fiscal_period'  => '2026-06',
        ]);

        $this->service->rollback($payroll, 'payment_and_accrual', 'Lương sai toàn bộ');

        $payroll->refresh();
        $item->refresh();

        // Bảng lương về draft
        $this->assertEquals(PayrollStatus::Draft, $payroll->status);

        // Dòng lương về pending
        $this->assertEquals(PayrollItemStatus::Pending, $item->status);

        // Bút toán lương (draft) đã bị xóa
        $this->assertDatabaseMissing('journal_entries', ['id' => $je->id]);
    }

    // ─── TC3: không có dòng nào paid → throws ────────────────────────────────

    public function test_tc3_no_paid_items_throws(): void
    {
        $payroll = $this->makePayroll('confirmed');
        $this->makePendingItem($payroll);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('không có dòng nào đã thanh toán');

        $this->service->rollback($payroll, 'payment_only', 'Test');
    }

    // ─── TC4: preview trả về đúng thông tin ──────────────────────────────────

    public function test_tc4_preview_returns_correct_data(): void
    {
        $payroll = $this->makePayroll('paid');
        $this->makePaidItem($payroll, 12_000_000);

        $preview = $this->service->preview($payroll);

        $this->assertEquals($payroll->code, $preview['payroll_code']);
        $this->assertEquals(1, $preview['paid_count']);
        $this->assertEquals(12_000_000, $preview['total_amount']);
        $this->assertCount(1, $preview['vouchers']);
        $this->assertTrue($preview['can_rollback']);
        $this->assertTrue($preview['current_period_open']);
    }

    // ─── TC5: cash_voucher_id và salary_journal_entry_id bị xóa sau rollback ─

    public function test_tc5_rollback_clears_voucher_and_je_link(): void
    {
        $payroll = $this->makePayroll('confirmed');
        $item    = $this->makePaidItem($payroll, 8_000_000);

        // Tạo JE thật và gán vào item
        $je = JournalEntry::create([
            'code'           => 'PKT-CASH-01',
            'entry_date'     => now()->toDateString(),
            'description'    => 'Test cash JE',
            'status'         => 'draft',
            'is_auto'        => false,
            'reference_type' => 'cash_voucher',
            'reference_id'   => $item->cash_voucher_id,
            'created_by'     => $this->user->id,
            'fiscal_period'  => '2026-06',
        ]);
        $item->update(['salary_journal_entry_id' => $je->id]);

        $this->service->rollback($payroll, 'payment_only', 'Test clear links');

        $item->refresh();
        $this->assertNull($item->cash_voucher_id);
        $this->assertNull($item->salary_journal_entry_id);
        $this->assertNull($item->paid_at);
    }

    // ─── TC6: item không có cashVoucher → không crash ────────────────────────

    public function test_tc6_item_without_voucher_does_not_crash(): void
    {
        $payroll = $this->makePayroll('confirmed');

        // Item paid nhưng cash_voucher_id = null (dữ liệu cũ / migration)
        PayrollItem::create([
            'payroll_id'       => $payroll->id,
            'net_salary'       => 7_000_000,
            'base_salary'      => 0,
            'allowance'        => 0,
            'bonus'            => 0,
            'gross_salary'     => 0,
            'insurance_base'   => 0,
            'bhxh_employee'    => 0,
            'bhyt_employee'    => 0,
            'bhtn_employee'    => 0,
            'bhxh_employer'    => 0,
            'bhyt_employer'    => 0,
            'bhtn_employer'    => 0,
            'pit'              => 0,
            'deductions'       => 0,
            'dependents_count' => 0,
            'working_days'     => 26,
            'standard_days'    => 26,
            'advance'          => 0,
            'insurance_subject' => false,
            'status'           => PayrollItemStatus::Paid,
            'paid_at'          => now(),
            'cash_voucher_id'  => null,
        ]);

        // Không nên throw
        $this->service->rollback($payroll, 'payment_only', 'Orphaned item');

        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Confirmed, $payroll->status);
        $this->assertDatabaseHas('payroll_items', [
            'payroll_id' => $payroll->id,
            'status'     => PayrollItemStatus::Pending->value,
        ]);
    }
}
