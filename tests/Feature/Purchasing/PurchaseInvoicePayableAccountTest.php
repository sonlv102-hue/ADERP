<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Kiểm tra routing TK Có (3311 / 3312) theo loại hàng hóa vs dịch vụ.
 *
 * P1: HĐ hàng hóa (header-level, invoice_type=resale_goods) → KHÔNG tạo JE từ invoice
 * P2: HĐ dịch vụ không dự án (management_expense, no items) → Cr 3312
 * P3: HĐ dịch vụ có dự án (items path, TK 154 → Cr 3312, project_id required)
 * P4: HĐ dịch vụ qua items — TK Có tự suy từ TK Nợ (154 → 3312, không set invoice_type)
 * P5: items có credit_account_code rõ ràng → dùng đúng, không override
 * P6: items mixed credit (3311 + 3312) → hai dòng Có riêng
 * P7: Nếu TK 154 không có project_id → tryPost() trả null (validation fail)
 * P8: HĐ management_expense header-level: Cr 3312 (không phải 3311)
 */
class PurchaseInvoicePayableAccountTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;
    private User $user;
    private Supplier $supplier;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseInvoiceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin_p@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Core accounts
        $this->seedAccount('331',  'liability', 'credit', false);
        $this->seedAccount('3311', 'liability', 'credit', true,  '331');
        $this->seedAccount('3312', 'liability', 'credit', true,  '331');
        $this->seedAccount('3318', 'liability', 'credit', true,  '331');
        $this->seedAccount('133',  'asset',     'debit',  false);
        $this->seedAccount('1331', 'asset',     'debit',  true,  '133');
        $this->seedAccount('642',  'expense',   'debit',  false);
        $this->seedAccount('6422', 'expense',   'debit',  true,  '642');
        $this->seedAccount('641',  'expense',   'debit',  false);
        $this->seedAccount('6421', 'expense',   'debit',  true,  '641');
        $this->seedAccount('154',  'asset',     'debit',  true);
        $this->seedAccount('152',  'asset',     'debit',  false);
        $this->seedAccount('1521', 'asset',     'debit',  true,  '152');

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-P-001',
            'name'                 => 'NCC Payable Test',
            'is_active'            => true,
            'payable_account_code' => '3311',
        ]);

        $customer = Customer::create(['code' => 'CUST-P', 'name' => 'KH Test', 'is_active' => true]);
        $this->project = Project::create([
            'code'        => 'DA-P001',
            'name'        => 'Project Test',
            'customer_id' => $customer->id,
            'status'      => 'planning',
            'created_by'  => $this->user->id,
        ]);
    }

    private function seedAccount(string $code, string $type, string $normalBalance, bool $isDetail, ?string $parentCode = null): void
    {
        if ($parentCode) {
            AccountCode::firstOrCreate(['code' => $parentCode], [
                'name' => 'TK ' . $parentCode, 'type' => $type,
                'normal_balance' => $normalBalance, 'parent_code' => null,
                'level' => 3, 'is_detail' => false, 'is_active' => true,
            ]);
        }
        AccountCode::firstOrCreate(['code' => $code], [
            'name' => 'TK ' . $code, 'type' => $type,
            'normal_balance' => $normalBalance, 'parent_code' => $parentCode,
            'level' => $parentCode ? 4 : 3, 'is_detail' => $isDetail, 'is_active' => true,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(['code' => 'WH-P'], ['name' => 'Kho test', 'is_active' => true]);
    }

    private function makePo(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'code'         => 'MH-P-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->makeWarehouse()->id,
            'status'       => 'sent',
            'order_date'   => '2026-06-22',
            'total'        => 11_000_000,
            'created_by'   => $this->user->id,
        ]);
    }

    private function makePi(PurchaseOrder $po, ?PurchaseInvoiceType $type = null, array $overrides = []): PurchaseInvoice
    {
        return PurchaseInvoice::create(array_merge([
            'code'              => 'HD-NCC-P-' . uniqid(),
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'subtotal'          => 10_000_000,
            'tax_amount'        => 1_000_000,
            'total'             => 11_000_000,
            'paid_amount'       => 0,
            'status'            => PurchaseInvoiceStatus::Reviewing,
            'invoice_type'      => $type,
            'invoice_date'      => '2026-06-22',
            'created_by'        => $this->user->id,
        ], $overrides));
    }

    // ─── P1: hàng hóa inventory-backed → không tạo JE từ invoice ────────────

    public function test_resale_goods_invoice_no_je(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ResaleGoods);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->first();

        $this->assertNull($je, 'HĐ hàng hóa: JE phải do StockService tạo khi NK confirm, không tạo ở invoice');
    }

    // ─── P2: dịch vụ không dự án (header-level) → Cr 3312 ───────────────────

    public function test_management_expense_header_uses_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ManagementExpense);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();

        $je->load('lines');
        $this->assertEquals(
            11_000_000,
            $je->lines->where('account_code', '3312')->sum('credit'),
            'Chi phí QLDN phải Có TK 3312'
        );
        $this->assertEquals(0, $je->lines->where('account_code', '3311')->sum('credit'), 'Không được Có 3311');
    }

    // ─── P3: dịch vụ dự án qua items path → Dr 154 project + Cr 3312 ────────

    public function test_service_invoice_item_tk154_with_project_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ProjectConstruction, [
            'project_id' => $this->project->id,
            'subtotal'   => 0,
            'tax_amount' => 0,
            'total'      => 0,
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description'         => 'Thuê nhân công thi công',
            'account_code'        => '154',
            'project_id'          => $this->project->id,
            'amount'              => 5_000_000,
            'vat_rate'            => 10,
            'tax_amount'          => 500_000,
            'sort_order'          => 1,
        ]);
        $pi->update(['subtotal' => 5_000_000, 'tax_amount' => 500_000, 'total' => 5_500_000]);
        $pi->refresh();

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();
        $je->load('lines');

        $this->assertEquals(5_000_000, $je->lines->where('account_code', '154')->sum('debit'), 'Nợ 154');
        $this->assertEquals(500_000,   $je->lines->where('account_code', '1331')->sum('debit'), 'Nợ 1331');
        $this->assertEquals(5_500_000, $je->lines->where('account_code', '3312')->sum('credit'), 'Có 3312');
        $this->assertEquals(0,         $je->lines->where('account_code', '3311')->sum('credit'), 'Không Có 3311');

        // Dòng 154 phải có project_id
        $line154 = $je->lines->where('account_code', '154')->first();
        $this->assertEquals($this->project->id, $line154->project_id, 'Dòng Nợ 154 phải gắn project_id');
    }

    // ─── P4: items không có invoice_type, TK Nợ 154 → suy ra Cr 3312 ────────

    public function test_item_tk154_no_type_derives_cr3312(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, null, [   // không set invoice_type
            'subtotal'   => 0,
            'tax_amount' => 0,
            'total'      => 0,
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description'         => 'Dịch vụ không có loại',
            'account_code'        => '154',
            'project_id'          => $this->project->id,
            'amount'              => 2_000_000,
            'vat_rate'            => 0,
            'tax_amount'          => 0,
            'sort_order'          => 1,
        ]);
        $pi->update(['subtotal' => 2_000_000, 'total' => 2_000_000]);
        $pi->refresh();

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();
        $je->load('lines');

        $this->assertEquals(2_000_000, $je->lines->where('account_code', '3312')->sum('credit'),
            'TK 154 (không có invoice_type) phải suy ra Cr 3312');
    }

    // ─── P5: item có credit_account_code rõ ràng → dùng đúng ─────────────────

    public function test_item_explicit_credit_account_is_respected(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ManagementExpense, [
            'subtotal'   => 0,
            'tax_amount' => 0,
            'total'      => 0,
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description'         => 'Chi phí QLD tự chọn TK Có',
            'account_code'        => '6422',
            'credit_account_code' => '3318',   // user chọn 3318 thay vì default 3312
            'project_id'          => null,
            'amount'              => 3_000_000,
            'vat_rate'            => 0,
            'tax_amount'          => 0,
            'sort_order'          => 1,
        ]);
        $pi->update(['subtotal' => 3_000_000, 'total' => 3_000_000]);
        $pi->refresh();

        // Seed 3318
        $this->seedAccount('3318', 'liability', 'credit', true, '331');

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();
        $je->load('lines');

        $this->assertEquals(3_000_000, $je->lines->where('account_code', '3318')->sum('credit'),
            'Phải dùng 3318 theo credit_account_code người dùng chọn');
        $this->assertEquals(0, $je->lines->where('account_code', '3312')->sum('credit'), 'Không được dùng 3312');
    }

    // ─── P6: items mixed credit (3311 + 3312) → hai dòng Có riêng ───────────

    public function test_mixed_items_create_separate_credit_lines(): void
    {
        $po = $this->makePo();
        $pi = $this->makePi($po, null, [
            'subtotal'   => 0,
            'tax_amount' => 0,
            'total'      => 0,
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description'         => 'Vật tư',
            'account_code'        => '1521',
            'credit_account_code' => '3311',
            'project_id'          => null,
            'amount'              => 4_000_000,
            'vat_rate'            => 10,
            'tax_amount'          => 400_000,
            'sort_order'          => 1,
        ]);
        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description'         => 'Dịch vụ',
            'account_code'        => '6422',
            'credit_account_code' => '3312',
            'project_id'          => null,
            'amount'              => 2_000_000,
            'vat_rate'            => 10,
            'tax_amount'          => 200_000,
            'sort_order'          => 2,
        ]);
        $pi->update(['subtotal' => 6_000_000, 'tax_amount' => 600_000, 'total' => 6_600_000]);
        $pi->refresh();

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();
        $je->load('lines');

        // Dr 1521 + Dr 6422 + Dr 1331; Cr 3311 (4.4M) + Cr 3312 (2.2M)
        $this->assertEquals(4_400_000, $je->lines->where('account_code', '3311')->sum('credit'),
            'Cr 3311 = 4M + 400K VAT');
        $this->assertEquals(2_200_000, $je->lines->where('account_code', '3312')->sum('credit'),
            'Cr 3312 = 2M + 200K VAT');
        $this->assertEquals(6_600_000, $je->lines->where('credit', '>', 0)->sum('credit'),
            'Tổng Có = 6.6M');
    }

    // ─── P8: management_expense header-level không dùng supplier.payable_account ─

    public function test_service_invoice_does_not_use_supplier_payable_3311(): void
    {
        // Supplier cấu hình 3311, nhưng invoice loại dịch vụ phải dùng 3312
        $this->assertEquals('3311', $this->supplier->payable_account_code);

        $po = $this->makePo();
        $pi = $this->makePi($po, PurchaseInvoiceType::ExternalService);

        $this->service->transition($pi, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $pi->id)->firstOrFail();
        $je->load('lines');

        $this->assertEquals(0,          $je->lines->where('account_code', '3311')->sum('credit'),
            'Dịch vụ không được vào 3311 dù supplier config là 3311');
        $this->assertEquals(11_000_000, $je->lines->where('account_code', '3312')->sum('credit'),
            'Dịch vụ phải vào 3312');
    }
}
