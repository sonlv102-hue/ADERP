<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockEntryStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountBalanceService;
use App\Services\ArApLedgerService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Kiểm tra luồng kế toán mua dịch vụ:
 *  - PI không có StockEntry → phải post JE: Dr expense / Dr 1331 / Cr 331
 *  - PI có StockEntry confirmed → không post JE (StockEntry đã post rồi)
 *  - Hủy PI dịch vụ → JE bị đảo ngược
 *  - TK 331 (AP) phải đúng sau khi post và sau khi thanh toán
 */
class ServicePurchaseJournalTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;
    private AccountBalanceService $balanceSvc;
    private ArApLedgerService $arAp;
    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service    = app(PurchaseInvoiceService::class);
        $this->balanceSvc = app(AccountBalanceService::class);
        $this->arAp       = app(ArApLedgerService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);

        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Seed tài khoản cần thiết
        $this->seedAccount('331',  'liability', 'credit', false);
        $this->seedAccount('3311', 'liability', 'credit', true,  '331');
        $this->seedAccount('133',  'asset',     'debit',  false);
        $this->seedAccount('1331', 'asset',     'debit',  true,  '133');
        $this->seedAccount('642',  'expense',   'debit',  false);
        $this->seedAccount('6422', 'expense',   'debit',  true,  '642');
        $this->seedAccount('111',  'asset',     'debit',  false);
        $this->seedAccount('1111', 'asset',     'debit',  true,  '111');

        $this->warehouse = Warehouse::create(['code' => 'KHO-01', 'name' => 'Kho chính', 'is_active' => true]);

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-0001',
            'name'                 => 'NCC Test',
            'is_active'            => true,
            'payable_account_code' => '3311',
        ]);
    }

    private function seedAccount(string $code, string $type, string $normalBalance, bool $isDetail, ?string $parentCode = null): AccountCode
    {
        if ($parentCode) {
            AccountCode::firstOrCreate(['code' => $parentCode], [
                'name' => 'TK ' . $parentCode, 'type' => $type,
                'normal_balance' => $normalBalance, 'parent_code' => null,
                'level' => 3, 'is_detail' => false, 'is_active' => true,
            ]);
        }

        return AccountCode::firstOrCreate(['code' => $code], [
            'name' => 'TK ' . $code, 'type' => $type,
            'normal_balance' => $normalBalance, 'parent_code' => $parentCode,
            'level' => $parentCode ? 4 : 3, 'is_detail' => $isDetail, 'is_active' => true,
        ]);
    }

    private function makePo(string $status = 'sent'): PurchaseOrder
    {
        return PurchaseOrder::create([
            'code'         => 'MH-TEST-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status'       => $status,
            'order_date'   => '2026-06-15',
            'total'        => 11_000_000,
            'created_by'   => $this->user->id,
        ]);
    }

    private function makePi(PurchaseOrder $po, string $expenseAccount = '6422'): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'code'                 => 'HD-NCC-' . uniqid(),
            'purchase_order_id'    => $po->id,
            'supplier_id'          => $this->supplier->id,
            'subtotal'             => 10_000_000,
            'tax_amount'           => 1_000_000,
            'total'                => 11_000_000,
            'paid_amount'          => 0,
            'status'               => PurchaseInvoiceStatus::Reviewing,
            'expense_account_code' => $expenseAccount,
            'invoice_date'         => '2026-06-15',
            'created_by'           => $this->user->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: PI không có StockEntry → chuyển valid → tạo JE Dr 6422/1331 / Cr 3311
    //       Auto-generated JE ở trạng thái 'draft' (kế toán review trước khi post)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_service_purchase_posts_journal_on_valid(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNotNull($je, 'JE phải được tạo cho mua dịch vụ');
        // isAuto=true → trạng thái draft (nhất quán với toàn bộ hệ thống auto-posting)
        $this->assertSame('draft', $je->status);

        $je->load('lines');
        $debitAccounts  = $je->lines->where('debit', '>', 0)->pluck('account_code')->sort()->values();
        $creditAccounts = $je->lines->where('credit', '>', 0)->pluck('account_code')->values();

        $this->assertContains('6422', $debitAccounts->toArray(), 'Phải có Dr 6422');
        $this->assertContains('1331', $debitAccounts->toArray(), 'Phải có Dr 1331');
        $this->assertContains('3311', $creditAccounts->toArray(), 'Phải có Cr 3311');

        // Số tiền
        $dr6422 = $je->lines->where('account_code', '6422')->sum('debit');
        $dr1331 = $je->lines->where('account_code', '1331')->sum('debit');
        $cr3311 = $je->lines->where('account_code', '3311')->sum('credit');

        $this->assertEquals(10_000_000, $dr6422);
        $this->assertEquals(1_000_000,  $dr1331);
        $this->assertEquals(11_000_000, $cr3311);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: PI có StockEntry confirmed → chuyển valid → KHÔNG post JE (StockEntry đã xử lý)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_goods_purchase_does_not_double_post_on_valid(): void
    {
        $po = $this->makePo('received');

        // Tạo StockEntry confirmed cho PO này
        $se = StockEntry::create([
            'code'              => 'NK-TEST-' . uniqid(),
            'purchase_order_id' => $po->id,
            'warehouse_id'      => 1,
            'status'            => StockEntryStatus::Confirmed,
            'entry_date'        => '2026-06-15',
            'created_by'        => $this->user->id,
        ]);

        $pi = $this->makePi($po);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        // Không được có JE với reference purchase_invoice
        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNull($je, 'Mua hàng hóa không được post JE riêng từ PI');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: Công nợ AP phải tăng sau khi PI dịch vụ chuyển valid
    //       Dùng ArApLedgerService (query invoice table, không phụ thuộc JE status)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ap_balance_increases_after_service_purchase_valid(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po);

        $totalBefore = $this->arAp->payables([], true)->sum('total');

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $totalAfter = $this->arAp->payables([], true)->sum('total');

        $this->assertEquals($totalBefore + 11_000_000, $totalAfter,
            'AP phải tăng 11M sau khi PI dịch vụ hợp lệ (ArApLedgerService query invoice table)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: Hủy PI dịch vụ → JE draft bị xóa (reverseOrDelete: draft → hard delete)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cancel_service_purchase_reverses_journal(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        // JE được tạo ở draft
        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();
        $this->assertNotNull($je);
        $this->assertSame('draft', $je->status);
        $jeId = $je->id;

        // Hủy PI
        $this->service->transition($pi, PurchaseInvoiceStatus::Cancelled);

        // JE draft bị hard-delete (reverseOrDelete: draft → delete, posted → reverse)
        $this->assertDatabaseMissing('journal_entries', ['id' => $jeId]);

        // PI chuyển về cancelled
        $pi->refresh();
        $this->assertSame('cancelled', $pi->status->value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: PI dịch vụ với expense_account_code tùy chỉnh (6421 thay 6422)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_custom_expense_account_is_used_in_journal(): void
    {
        $this->seedAccount('6421', 'expense', 'debit', true, '642');

        $po = $this->makePo();
        $pi = $this->makePi($po, '6421');

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNotNull($je);
        $dr = $je->lines()->where('account_code', '6421')->sum('debit');
        $this->assertEquals(10_000_000, $dr, 'Phải dùng TK 6421 theo expense_account_code');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC6: Idempotent — chuyển valid 2 lần không post JE lần 2
    // ─────────────────────────────────────────────────────────────────────────

    public function test_posting_is_idempotent_via_accounting_posting_job(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        // Gọi lại postInvoiceEntryIfNeeded qua reflection (simulate retry)
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('postInvoiceEntryIfNeeded');
        $method->setAccessible(true);
        $method->invoke($this->service, $pi);

        // Chỉ có 1 JE (tryPost idempotent qua AccountingPostingJob)
        $count = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->count();

        $this->assertEquals(1, $count, 'tryPost phải idempotent — không tạo JE trùng');
    }
}
