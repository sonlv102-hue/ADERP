<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Kiểm tra routing kế toán theo invoice_type (TT133).
 *
 * TC1: management_expense → Dr 6422 + Dr 1331 / Cr 3312 (dịch vụ)
 * TC2: selling_expense     → Dr 6421 + Dr 1331 / Cr 3312 (dịch vụ)
 * TC3: project_construction→ Dr 154  + Dr 1331 / Cr 3312 (dịch vụ)
 * TC4: prepaid_expense     → Dr 242  + Dr 1331 / Cr 3312 (dịch vụ)
 * TC5: resale_goods (không có NK) → KHÔNG tạo JE
 * TC6: fixed_asset         → KHÔNG tạo JE (FixedAssetService xử lý)
 */
class PurchaseInvoiceTypeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;
    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseInvoiceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);

        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Seed tài khoản
        $this->seedAccount('331',  'liability', 'credit', false);
        $this->seedAccount('3311', 'liability', 'credit', true,  '331');
        $this->seedAccount('3312', 'liability', 'credit', true,  '331');
        $this->seedAccount('133',  'asset',     'debit',  false);
        $this->seedAccount('1331', 'asset',     'debit',  true,  '133');
        $this->seedAccount('642',  'expense',   'debit',  false);
        $this->seedAccount('6421', 'expense',   'debit',  true,  '642');
        $this->seedAccount('6422', 'expense',   'debit',  true,  '642');
        $this->seedAccount('154',  'asset',     'debit',  true);    // WIP dự án (detail)
        $this->seedAccount('242',  'asset',     'debit',  true);    // Chi phí trả trước (detail)

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

    private function makePo(): PurchaseOrder
    {
        $warehouse = Warehouse::firstOrCreate(['code' => 'KHO-01'], ['name' => 'Kho chính', 'is_active' => true]);

        return PurchaseOrder::create([
            'code'         => 'MH-TEST-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
            'status'       => 'sent',
            'order_date'   => '2026-06-15',
            'total'        => 11_000_000,
            'created_by'   => $this->user->id,
        ]);
    }

    private function makePi(PurchaseOrder $po, PurchaseInvoiceType $type, ?string $expenseAccount = null): PurchaseInvoice
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
            'invoice_type'         => $type,
            'expense_account_code' => $expenseAccount,
            'invoice_date'         => '2026-06-15',
            'created_by'           => $this->user->id,
        ]);
    }

    private function assertJeLines(PurchaseInvoice $pi, array $expectedDebits, array $expectedCredits): void
    {
        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNotNull($je, "JE phải được tạo cho PI #{$pi->id}");

        $je->load('lines');
        $drAccounts = $je->lines->where('debit', '>', 0)->pluck('account_code')->sort()->values()->toArray();
        $crAccounts = $je->lines->where('credit', '>', 0)->pluck('account_code')->values()->toArray();

        foreach ($expectedDebits as $account) {
            $this->assertContains($account, $drAccounts, "Phải có Dr {$account}");
        }
        foreach ($expectedCredits as $account) {
            $this->assertContains($account, $crAccounts, "Phải có Cr {$account}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: invoice_type = management_expense → Dr 6422 + Dr 1331 / Cr 3312
    // ─────────────────────────────────────────────────────────────────────────

    public function test_management_expense_posts_dr6422_dr1331_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ManagementExpense);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $this->assertJeLines($pi, ['6422', '1331'], ['3312']);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->first();
        $this->assertEquals(10_000_000, $je->lines->where('account_code', '6422')->sum('debit'));
        $this->assertEquals(1_000_000,  $je->lines->where('account_code', '1331')->sum('debit'));
        $this->assertEquals(11_000_000, $je->lines->where('account_code', '3312')->sum('credit'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: invoice_type = selling_expense → Dr 6421 + Dr 1331 / Cr 3312
    // ─────────────────────────────────────────────────────────────────────────

    public function test_selling_expense_posts_dr6421_dr1331_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::SellingExpense);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $this->assertJeLines($pi, ['6421', '1331'], ['3312']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: invoice_type = project_construction → Dr 154 + Dr 1331 / Cr 3312
    // ─────────────────────────────────────────────────────────────────────────

    public function test_project_construction_posts_dr154_dr1331_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ProjectConstruction);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $this->assertJeLines($pi, ['154', '1331'], ['3312']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: invoice_type = prepaid_expense → Dr 242 + Dr 1331 / Cr 3312
    // ─────────────────────────────────────────────────────────────────────────

    public function test_prepaid_expense_posts_dr242_dr1331_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::PrepaidExpense);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $this->assertJeLines($pi, ['242', '1331'], ['3312']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: invoice_type = resale_goods (không có NK) → KHÔNG tạo JE từ invoice
    // Bút toán sẽ tạo khi StockService xác nhận phiếu nhập kho
    // ─────────────────────────────────────────────────────────────────────────

    public function test_resale_goods_does_not_create_je_even_without_stock_entry(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ResaleGoods);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNull($je, 'Hàng hóa bán lại không được tạo JE từ invoice (StockService xử lý)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC6: invoice_type = fixed_asset → KHÔNG tạo JE (FixedAssetService xử lý sau)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_fixed_asset_does_not_create_je_on_valid(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::FixedAsset);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)
            ->first();

        $this->assertNull($je, 'TSCĐ không tạo JE từ invoice (FixedAssetService xử lý khi ghi nhận TSCĐ)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC7: expense_account_code override dùng đúng TK Nợ; TK Có vẫn là 3312
    // (external_service với expense_account_code = '6421')
    // ─────────────────────────────────────────────────────────────────────────

    public function test_external_service_with_custom_account_uses_override(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ExternalService, '6421');

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->first();
        $this->assertNotNull($je);

        $dr = $je->lines()->where('account_code', '6421')->sum('debit');
        $this->assertEquals(10_000_000, $dr, 'Phải dùng 6421 theo expense_account_code override');

        $cr = $je->lines()->where('account_code', '3312')->sum('credit');
        $this->assertEquals(11_000_000, $cr, 'TK Có phải là 3312 cho external_service');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC8: isGoodsPurchase() trả về đúng theo invoice_type (không cần PO items)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_is_goods_purchase_respects_explicit_invoice_type(): void
    {
        $po = $this->makePo();

        $goodsInvoice = $this->makePi($po, PurchaseInvoiceType::ResaleGoods);
        $serviceInvoice = $this->makePi($po, PurchaseInvoiceType::ManagementExpense);
        $fixedAssetInvoice = $this->makePi($po, PurchaseInvoiceType::FixedAsset);

        $this->assertTrue($this->service->isGoodsPurchase($goodsInvoice), 'resale_goods → isGoods = true');
        $this->assertFalse($this->service->isGoodsPurchase($serviceInvoice), 'management_expense → isGoods = false');
        $this->assertFalse($this->service->isGoodsPurchase($fixedAssetInvoice), 'fixed_asset → isGoods = false');
    }
}
