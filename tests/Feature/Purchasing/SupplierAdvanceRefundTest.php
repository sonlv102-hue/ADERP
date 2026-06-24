<?php

namespace Tests\Feature\Purchasing;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Services\SupplierAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test thu hồi tiền trả trước NCC (supplier_advance_refunds).
 *
 * TC1:  Thu hồi tiền mặt từ opening_balance → JE Dr 1111 / Cr 331UT + remaining giảm
 * TC2:  Thu hồi ngân hàng từ opening_balance → JE Dr 1121 / Cr 331UT
 * TC3:  Thu hồi quá số còn lại → RuntimeException
 * TC4:  Thu hồi bằng đúng remaining → status fully_applied + remaining = 0
 * TC5:  Thu hồi một phần → status partially_applied + remaining giảm đúng
 * TC6:  Thu hồi sau khi đã cấn trừ một phần (remaining < amount)
 * TC7:  Không thu hồi được khoản đã hủy
 * TC8:  Sửa opening_balance không có allocation → OK
 * TC9:  Sửa opening_balance có active allocation → RuntimeException
 * TC10: Xóa opening_balance không có allocation → xóa được
 */
class SupplierAdvanceRefundTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAdvanceService $service;
    private User $user;
    private Supplier $supplier;
    private Fund $fund;
    private BankAccount $bank;

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

        foreach ([
            ['1111',  'Tiền mặt VND',             'asset',     'debit',  null,   2, true],
            ['1121',  'Tiền gửi ngân hàng VND',    'asset',     'debit',  null,   2, true],
            ['331',   'Phải trả NCC',              'liability', 'credit', null,   2, false],
            ['3311',  'Phải trả NCC chi tiết',     'liability', 'credit', '331',  3, true],
            ['331UT', 'Trả trước người bán',        'asset',     'debit',  '331',  4, true],
        ] as [$code, $name, $type, $nb, $parent, $level, $isDetail]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb,
                'parent_code' => $parent, 'level' => $level,
                'is_detail' => $isDetail, 'is_active' => true,
            ]);
        }

        $this->supplier = Supplier::create([
            'code' => 'NCC-REFUND-TEST', 'name' => 'NCC Test Refund',
            'payable_account_code' => '3311',
        ]);

        $this->fund = Fund::create([
            'code' => 'QUY-TEST', 'name' => 'Quỹ TM Test',
            'type' => 'cash', 'account_code' => '1111', 'is_active' => true,
        ]);

        $this->bank = BankAccount::create([
            'name'           => 'BIDV Test',
            'bank_name'      => 'BIDV',
            'account_number' => '12345678',
            'account_name'   => 'Cty Test',
            'account_code'   => '1121',
        ]);
    }

    private function makeOpeningBalance(float $amount = 10_000_000): SupplierOpeningAdvance
    {
        return $this->service->create([
            'supplier_id'   => $this->supplier->id,
            'advance_type'  => 'opening_balance',
            'opening_date'  => '2026-01-01',
            'fiscal_year'   => 2026,
            'account_code'  => '331UT',
            'amount'        => $amount,
            'created_by'    => $this->user->id,
        ]);
    }

    // TC1 ────────────────────────────────────────────────────────────────────
    public function test_tc1_refund_cash_creates_je_and_reduces_remaining(): void
    {
        $adv = $this->makeOpeningBalance(10_000_000);

        $refund = $this->service->refund($adv, [
            'refund_date'   => '2026-06-10',
            'amount'        => 3_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
            'description'   => 'Thu hồi tiền mặt',
        ]);

        $adv->refresh();

        $this->assertDatabaseHas('supplier_advance_refunds', [
            'supplier_advance_id' => $adv->id,
            'status'              => 'confirmed',
            'refund_method'       => 'cash',
        ]);

        // JE Dr 1111 / Cr 331UT
        $je = JournalEntry::find($refund->journal_entry_id);
        $this->assertNotNull($je);
        $lines = $je->lines()->get()->keyBy('account_code');
        $this->assertEquals(3_000_000, (float) $lines['1111']->debit);
        $this->assertEquals(3_000_000, (float) $lines['331UT']->credit);

        // remaining = 10M - 3M = 7M
        $this->assertEquals(7_000_000, (float) $adv->remaining_amount);
        $this->assertEquals(3_000_000, (float) $adv->refunded_amount);
        $this->assertEquals('partially_applied', $adv->status);
    }

    // TC2 ────────────────────────────────────────────────────────────────────
    public function test_tc2_refund_bank_creates_je_dr_1121(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);

        $refund = $this->service->refund($adv, [
            'refund_date'     => '2026-06-10',
            'amount'          => 2_000_000,
            'refund_method'   => 'bank',
            'bank_account_id' => $this->bank->id,
        ]);

        $adv->refresh();
        $je = JournalEntry::find($refund->journal_entry_id);
        $lines = $je->lines()->get()->keyBy('account_code');

        $this->assertEquals(2_000_000, (float) $lines['1121']->debit);
        $this->assertEquals(2_000_000, (float) $lines['331UT']->credit);
        $this->assertEquals(3_000_000, (float) $adv->remaining_amount);
    }

    // TC3 ────────────────────────────────────────────────────────────────────
    public function test_tc3_refund_exceeds_remaining_throws(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vượt quá/');

        $this->service->refund($adv, [
            'refund_date'   => '2026-06-10',
            'amount'        => 9_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
        ]);
    }

    // TC4 ────────────────────────────────────────────────────────────────────
    public function test_tc4_full_refund_sets_fully_applied_and_zero_remaining(): void
    {
        $adv = $this->makeOpeningBalance(4_000_000);

        $this->service->refund($adv, [
            'refund_date'   => '2026-06-10',
            'amount'        => 4_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
        ]);

        $adv->refresh();
        $this->assertEquals(0, (float) $adv->remaining_amount);
        $this->assertEquals(4_000_000, (float) $adv->refunded_amount);
        $this->assertEquals('fully_applied', $adv->status);
    }

    // TC5 ────────────────────────────────────────────────────────────────────
    public function test_tc5_partial_refund_sets_partially_applied(): void
    {
        $adv = $this->makeOpeningBalance(8_000_000);

        $this->service->refund($adv, [
            'refund_date'   => '2026-06-10',
            'amount'        => 2_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
        ]);

        $adv->refresh();
        $this->assertEquals(6_000_000, (float) $adv->remaining_amount);
        $this->assertEquals('partially_applied', $adv->status);
    }

    // TC6 ────────────────────────────────────────────────────────────────────
    public function test_tc6_refund_after_partial_allocation(): void
    {
        $adv = $this->makeOpeningBalance(10_000_000);

        // Giả lập: manually set remaining thấp hơn (vì allocation đã giảm)
        $adv->update(['remaining_amount' => 6_000_000, 'status' => 'partially_applied']);

        $this->service->refund($adv, [
            'refund_date'   => '2026-06-15',
            'amount'        => 6_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
        ]);

        $adv->refresh();
        $this->assertEquals(0, (float) $adv->remaining_amount);
        $this->assertEquals('fully_applied', $adv->status);
    }

    // TC7 ────────────────────────────────────────────────────────────────────
    public function test_tc7_cannot_refund_cancelled_advance(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);
        $adv->update(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã hủy/');

        $this->service->refund($adv, [
            'refund_date'   => '2026-06-10',
            'amount'        => 1_000_000,
            'refund_method' => 'cash',
            'fund_id'       => $this->fund->id,
        ]);
    }

    // TC8 ────────────────────────────────────────────────────────────────────
    public function test_tc8_edit_opening_balance_without_allocations_ok(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);

        $this->service->update($adv, [
            'supplier_id'   => $this->supplier->id,
            'opening_date'  => '2026-01-15',
            'fiscal_year'   => 2026,
            'amount'        => 6_000_000,
            'reference_no'  => 'REF-001',
        ]);

        $adv->refresh();
        $this->assertEquals(6_000_000, (float) $adv->amount);
        $this->assertEquals('REF-001', $adv->reference_no);
    }

    // TC9 ────────────────────────────────────────────────────────────────────
    public function test_tc9_edit_advance_with_active_allocation_blocked(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);

        // Seed an active allocation
        \App\Models\SupplierAdvanceAllocation::create([
            'opening_advance_id' => $adv->id,
            'supplier_id'        => $this->supplier->id,
            'allocation_date'    => '2026-06-01',
            'allocated_amount'   => 1_000_000,
            'status'             => 'active',
            'created_by'         => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->update($adv, [
            'supplier_id'  => $this->supplier->id,
            'opening_date' => '2026-01-15',
            'fiscal_year'  => 2026,
            'amount'       => 3_000_000,
        ]);
    }

    // TC10 ───────────────────────────────────────────────────────────────────
    public function test_tc10_delete_opening_balance_no_allocations(): void
    {
        $adv = $this->makeOpeningBalance(5_000_000);
        $id  = $adv->id;

        $this->actingAs($this->user)
            ->delete(route('purchasing.supplier-advances.destroy', $adv->id))
            ->assertRedirect(route('purchasing.supplier-advances.index'));

        $this->assertDatabaseMissing('supplier_opening_advances', ['id' => $id]);
    }
}
