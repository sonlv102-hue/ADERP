<?php

namespace Tests\Feature\Purchasing;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Services\CashVoucherService;
use App\Services\SupplierAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test: Xóa mềm trả trước NCC (deleteSafely)
 *
 * TC1: Xóa khoản opening_balance không có JE → soft delete thành công
 * TC2: Xóa khoản đã hủy (cancelled) → soft delete, không tạo JE đảo
 * TC3: Xóa khoản open prepayment, JE posted → hủy CashVoucher + đảo JE + soft delete
 * TC4: Xóa khoản có allocation active → RuntimeException, không xóa
 * TC5: Xóa khoản partially_applied → RuntimeException, không xóa
 * TC6: Sau xóa, query mặc định không trả khoản đã xóa; withTrashed thì có
 */
class SupplierAdvanceDeleteTest extends TestCase
{
    use RefreshDatabase;

    private SupplierAdvanceService $service;
    private CashVoucherService $cashVoucherService;
    private User $user;
    private Supplier $supplier;
    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service            = app(SupplierAdvanceService::class);
        $this->cashVoucherService = app(CashVoucherService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@delete-test.local'],
            ['name' => 'Admin Delete Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

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
            'code'         => 'QUY-DEL-TEST',
            'name'         => 'Quỹ Delete Test',
            'type'         => 'cash',
            'account_code' => '1111',
            'is_active'    => true,
        ]);

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-DEL-TEST',
            'name'                 => 'NCC Delete Test',
            'phone'                => '0901234567',
            'payable_account_code' => '3311',
        ]);
    }

    private function makeOpeningBalance(): SupplierOpeningAdvance
    {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $this->supplier->id,
            'advance_type'     => 'opening_balance',
            'fiscal_year'      => 2026,
            'opening_date'     => '2026-01-01',
            'account_code'     => '331UT',
            'amount'           => 10_000_000,
            'remaining_amount' => 10_000_000,
            'refunded_amount'  => 0,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);
    }

    private function makePrepayment(): SupplierOpeningAdvance
    {
        $adv = SupplierOpeningAdvance::create([
            'supplier_id'      => $this->supplier->id,
            'advance_type'     => 'prepayment',
            'fiscal_year'      => 2026, // nullable in prod (PG); SQLite test needs a value
            'opening_date'     => '2026-06-01',
            'account_code'     => '331UT',
            'amount'           => 5_000_000,
            'remaining_amount' => 5_000_000,
            'refunded_amount'  => 0,
            'status'           => 'open',
            'created_by'       => $this->user->id,
        ]);

        $voucher = CashVoucher::create([
            'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
            'type'           => CashVoucherType::Payment,
            'status'         => CashVoucherStatus::Draft,
            'fund_id'        => $this->fund->id,
            'supplier_id'    => $this->supplier->id,
            'partner_type'   => 'supplier',
            'amount'         => 5_000_000,
            'voucher_date'   => '2026-06-01',
            'description'    => 'Trả trước test',
            'business_type'  => 'supplier_prepayment',
            'reference_type' => SupplierOpeningAdvance::class,
            'reference_id'   => $adv->id,
            'created_by'     => $this->user->id,
        ]);
        $this->cashVoucherService->confirm($voucher);

        return $adv->fresh();
    }

    // TC1: Opening balance không có JE → soft delete thành công, ghi deleted_by + delete_reason
    public function test_delete_opening_balance_no_je_succeeds(): void
    {
        $adv = $this->makeOpeningBalance();

        $this->service->deleteSafely($adv, 'Test xóa đầu kỳ');

        $this->assertSoftDeleted('supplier_opening_advances', ['id' => $adv->id]);

        $deleted = SupplierOpeningAdvance::withTrashed()->find($adv->id);
        $this->assertEquals($this->user->id, $deleted->deleted_by);
        $this->assertEquals('Test xóa đầu kỳ', $deleted->delete_reason);
    }

    // TC2: Khoản đã hủy → soft delete, không tạo thêm JE đảo
    public function test_delete_cancelled_advance_no_je_reversal(): void
    {
        $adv = $this->makeOpeningBalance();
        $adv->update(['status' => 'cancelled']);

        $jeBefore = JournalEntry::count();

        $this->service->deleteSafely($adv, 'Đã hủy rồi, xóa khỏi danh sách');

        $this->assertSoftDeleted('supplier_opening_advances', ['id' => $adv->id]);
        $this->assertEquals($jeBefore, JournalEntry::count(), 'Không được tạo thêm JE khi khoản đã cancelled');
    }

    // TC3: Open prepayment có CashVoucher confirmed → hủy voucher + đảo JE + soft delete
    public function test_delete_open_prepayment_cancels_voucher_and_reverses_je(): void
    {
        $adv = $this->makePrepayment();

        $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
            ->where('reference_id', $adv->id)->firstOrFail();
        $this->assertEquals(CashVoucherStatus::Confirmed->value, $voucher->status->value);

        $jeBefore = JournalEntry::count();

        $this->service->deleteSafely($adv, 'Xóa trả trước sai');

        $this->assertSoftDeleted('supplier_opening_advances', ['id' => $adv->id]);

        $voucher->refresh();
        $this->assertEquals(
            CashVoucherStatus::Cancelled->value,
            $voucher->status->value,
            'CashVoucher phải bị hủy'
        );
        $this->assertGreaterThan($jeBefore, JournalEntry::count(), 'Phải có JE đảo sau khi hủy voucher');
    }

    // TC4: Khoản có active allocation → RuntimeException, không xóa
    public function test_delete_with_active_allocation_throws(): void
    {
        $adv = $this->makeOpeningBalance();

        SupplierAdvanceAllocation::create([
            'supplier_id'        => $this->supplier->id,
            'opening_advance_id' => $adv->id,
            'allocation_date'    => '2026-06-01',
            'allocated_amount'   => 5_000_000,
            'status'             => 'active',
            'created_by'         => $this->user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đối trừ đang hoạt động/');

        $this->service->deleteSafely($adv, 'Cố xóa khi có allocation');
    }

    // TC5: Khoản partially_applied → RuntimeException
    public function test_delete_partially_applied_throws(): void
    {
        $adv = $this->makeOpeningBalance();
        $adv->update(['status' => 'partially_applied', 'remaining_amount' => 5_000_000]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã đối trừ/');

        $this->service->deleteSafely($adv);
    }

    // TC6: Sau xóa mềm, query mặc định không trả về; withTrashed thì có
    public function test_deleted_advance_excluded_from_default_query(): void
    {
        $adv = $this->makeOpeningBalance();
        $id  = $adv->id;

        $this->service->deleteSafely($adv, 'Xóa test');

        $this->assertNull(SupplierOpeningAdvance::find($id), 'Khoản đã xóa không được hiện qua query mặc định');

        $withDeleted = SupplierOpeningAdvance::withTrashed()->find($id);
        $this->assertNotNull($withDeleted);
        $this->assertNotNull($withDeleted->deleted_at);
    }
}
