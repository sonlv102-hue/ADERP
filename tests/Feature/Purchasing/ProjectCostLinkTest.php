<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TC1: PI item TK 154 + project_id → JE line Nợ 154 có project_id đúng.
 * TC2: PI item TK 154 không có project_id → controller validation lỗi.
 * TC3: Một PI có 2 item TK 154 thuộc 2 dự án → JE lines giữ đúng project_id riêng.
 * TC4: PI invoice_type=project_construction + header project_id → JE line có project_id (legacy path).
 * TC5: PI item TK 6422 (không phải 154) → project_id có thể null, không block.
 * TC6: Backfill dry-run không thay đổi DB.
 * TC7: Backfill --apply cập nhật JE line từ invoice.project_id.
 * TC8: Audit command phát hiện JE line TK 154 thiếu project_id.
 * TC9: PI có item TK 154 + project_id → postFromItems tạo đúng số dòng JE.
 */
class ProjectCostLinkTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;
    private User     $user;
    private Supplier $supplier;
    private Project  $project1;
    private Project  $project2;

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

        $this->seedAccount('331',  'liability', 'credit', false);
        $this->seedAccount('3311', 'liability', 'credit', true, '331');
        $this->seedAccount('133',  'asset',     'debit',  false);
        $this->seedAccount('1331', 'asset',     'debit',  true, '133');
        $this->seedAccount('154',  'asset',     'debit',  true);
        $this->seedAccount('642',  'expense',   'debit',  false);
        $this->seedAccount('6422', 'expense',   'debit',  true, '642');

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-0001',
            'name'                 => 'NCC Test',
            'is_active'            => true,
            'payable_account_code' => '3311',
        ]);

        $customer = Customer::create([
            'code'      => 'KH-0001',
            'name'      => 'Khách hàng Test',
            'is_active' => true,
        ]);

        $this->project1 = Project::create([
            'code'        => 'DA-0001',
            'name'        => 'Dự án 1',
            'status'      => ProjectStatus::InProgress,
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);

        $this->project2 = Project::create([
            'code'        => 'DA-0002',
            'name'        => 'Dự án 2',
            'status'      => ProjectStatus::InProgress,
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);
    }

    // ─── TC1 ──────────────────────────────────────────────────────────────────

    /** TC1: Item TK 154 + project_id → JE line có project_id */
    public function test_item_154_with_project_creates_je_line_with_project_id(): void
    {
        $inv = $this->makeInvoiceWithItems([
            ['account_code' => '154', 'project_id' => $this->project1->id, 'amount' => 10_000_000, 'vat_rate' => 10, 'tax_amount' => 1_000_000],
        ]);

        $this->service->transition($inv, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $inv->id)
            ->first();

        $this->assertNotNull($je, 'JE phải được tạo');

        $line154 = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '154')
            ->where('debit', '>', 0)
            ->first();

        $this->assertNotNull($line154, 'Phải có dòng Nợ 154');
        $this->assertEquals($this->project1->id, $line154->project_id, 'project_id phải khớp');
    }

    // ─── TC2 ──────────────────────────────────────────────────────────────────

    /** TC2: Item TK 154 không có project_id → controller trả lỗi validation */
    public function test_item_154_without_project_id_fails_controller_validation(): void
    {
        $po = $this->makePo();

        $this->expectException(ValidationException::class);

        $inv = PurchaseInvoice::create([
            'code'              => 'HD-NCC-TEST2',
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'subtotal'          => 10_000_000,
            'tax_amount'        => 1_000_000,
            'total'             => 11_000_000,
            'paid_amount'       => 0,
            'status'            => PurchaseInvoiceStatus::Reviewing,
            'invoice_type'      => PurchaseInvoiceType::ProjectConstruction,
            'invoice_date'      => '2026-06-15',
            'created_by'        => $this->user->id,
        ]);

        // Simulate validateItemProjectLinks via reflection or direct call
        $controller = app(\App\Http\Controllers\Purchasing\PurchaseInvoiceController::class);
        $method = new \ReflectionMethod($controller, 'validateItemProjectLinks');
        $method->setAccessible(true);
        $method->invoke($controller, [
            ['account_code' => '154', 'project_id' => null, 'amount' => 10_000_000],
        ]);
    }

    // ─── TC3 ──────────────────────────────────────────────────────────────────

    /** TC3: 2 items TK 154 cho 2 dự án → 2 JE lines với đúng project_id riêng */
    public function test_two_154_items_different_projects_create_separate_je_lines(): void
    {
        $inv = $this->makeInvoiceWithItems([
            ['account_code' => '154', 'project_id' => $this->project1->id, 'amount' => 5_000_000, 'vat_rate' => 10, 'tax_amount' => 500_000],
            ['account_code' => '154', 'project_id' => $this->project2->id, 'amount' => 3_000_000, 'vat_rate' => 10, 'tax_amount' => 300_000],
        ]);

        $this->service->transition($inv, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $inv->id)
            ->first();

        $this->assertNotNull($je);

        $lines154 = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '154')
            ->where('debit', '>', 0)
            ->get();

        $this->assertCount(2, $lines154, 'Phải có 2 dòng Nợ 154');

        $projectIds = $lines154->pluck('project_id')->sort()->values();
        $this->assertEquals(
            collect([$this->project1->id, $this->project2->id])->sort()->values(),
            $projectIds
        );
    }

    // ─── TC4 ──────────────────────────────────────────────────────────────────

    /** TC4: Legacy path (không có items) + header project_id → JE line 154 có project_id */
    public function test_legacy_path_header_project_id_propagates_to_je_line(): void
    {
        $po  = $this->makePo();
        $inv = PurchaseInvoice::create([
            'code'              => 'HD-NCC-TC4',
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'project_id'        => $this->project1->id,
            'subtotal'          => 10_000_000,
            'tax_amount'        => 1_000_000,
            'total'             => 11_000_000,
            'paid_amount'       => 0,
            'status'            => PurchaseInvoiceStatus::Reviewing,
            'invoice_type'      => PurchaseInvoiceType::ProjectConstruction,
            'invoice_date'      => '2026-06-15',
            'created_by'        => $this->user->id,
        ]);

        $this->service->transition($inv, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $inv->id)->first();

        $this->assertNotNull($je);

        $line = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '154')->where('debit', '>', 0)->first();

        $this->assertNotNull($line);
        $this->assertEquals($this->project1->id, $line->project_id);
    }

    // ─── TC5 ──────────────────────────────────────────────────────────────────

    /** TC5: Item TK 6422 (không phải 154) không cần project_id */
    public function test_non_154_item_without_project_id_is_allowed(): void
    {
        $inv = $this->makeInvoiceWithItems([
            ['account_code' => '6422', 'project_id' => null, 'amount' => 5_000_000, 'vat_rate' => 10, 'tax_amount' => 500_000],
        ]);

        $this->service->transition($inv, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $inv->id)->first();

        $this->assertNotNull($je);

        $line = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '6422')->where('debit', '>', 0)->first();

        $this->assertNotNull($line);
        $this->assertNull($line->project_id, 'TK 6422 không cần project_id');
    }

    // ─── TC6 ──────────────────────────────────────────────────────────────────

    /** TC6: Backfill dry-run không thay đổi DB */
    public function test_backfill_dry_run_does_not_modify_db(): void
    {
        // Tạo JE line 154 thiếu project_id (giả lập dữ liệu cũ)
        $po  = $this->makePo();
        $inv = PurchaseInvoice::create([
            'code'              => 'HD-NCC-TC6',
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'project_id'        => $this->project1->id,
            'subtotal'          => 10_000_000, 'tax_amount' => 0, 'total' => 10_000_000,
            'paid_amount'       => 0, 'status' => PurchaseInvoiceStatus::Valid,
            'invoice_date'      => '2026-06-01', 'created_by' => $this->user->id,
        ]);

        $je = JournalEntry::create([
            'code'           => 'BT-TC6',
            'entry_date'     => '2026-06-01',
            'description'    => 'Test',
            'status'         => 'posted',
            'is_auto'        => true,
            'reference_type' => 'purchase_invoice',
            'reference_id'   => $inv->id,
            'created_by'     => $this->user->id,
        ]);

        $line = JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_code'     => '154',
            'debit'            => 10_000_000,
            'credit'           => 0,
            'project_id'       => null, // thiếu
            'sort_order'       => 1,
        ]);

        $this->artisan('projects:backfill-cost-links', ['--dry-run' => true])
            ->assertExitCode(0);

        $line->refresh();
        $this->assertNull($line->project_id, 'Dry-run không được thay đổi project_id');
    }

    // ─── TC7 ──────────────────────────────────────────────────────────────────

    /** TC7: Backfill --apply cập nhật JE line 154 từ invoice.project_id */
    public function test_backfill_apply_updates_je_line_project_id(): void
    {
        $po  = $this->makePo();
        $inv = PurchaseInvoice::create([
            'code'              => 'HD-NCC-TC7',
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'project_id'        => $this->project1->id,
            'subtotal'          => 10_000_000, 'tax_amount' => 0, 'total' => 10_000_000,
            'paid_amount'       => 0, 'status' => PurchaseInvoiceStatus::Valid,
            'invoice_date'      => '2026-06-01', 'created_by' => $this->user->id,
        ]);

        $je = JournalEntry::create([
            'code'           => 'BT-TC7',
            'entry_date'     => '2026-06-01',
            'description'    => 'Test',
            'status'         => 'posted',
            'is_auto'        => true,
            'reference_type' => 'purchase_invoice',
            'reference_id'   => $inv->id,
            'created_by'     => $this->user->id,
        ]);

        $line = JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_code'     => '154',
            'debit'            => 10_000_000,
            'credit'           => 0,
            'project_id'       => null,
            'sort_order'       => 1,
        ]);

        $this->artisan('projects:backfill-cost-links', ['--apply' => true])
            ->assertExitCode(0);

        $line->refresh();
        $this->assertEquals($this->project1->id, $line->project_id, 'project_id phải được cập nhật');
    }

    // ─── TC8 ──────────────────────────────────────────────────────────────────

    /** TC8: Audit command phát hiện JE line 154 thiếu project_id */
    public function test_audit_command_detects_154_lines_without_project_id(): void
    {
        $je = JournalEntry::create([
            'code'        => 'BT-TC8',
            'entry_date'  => '2026-06-01',
            'description' => 'Test',
            'status'      => 'posted',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_code'     => '154',
            'debit'            => 5_000_000,
            'credit'           => 0,
            'project_id'       => null,
            'sort_order'       => 1,
        ]);

        $this->artisan('projects:cost-link-audit')
            ->assertExitCode(1); // failure = có vấn đề
    }

    // ─── TC9 ──────────────────────────────────────────────────────────────────

    /** TC9: postFromItems tạo đúng số JE lines (item lines + vat line + cr line) */
    public function test_post_from_items_creates_correct_number_of_je_lines(): void
    {
        // 2 items TK 154 → 2 debit lines + 1 VAT line + 1 credit line = 4 lines total
        $inv = $this->makeInvoiceWithItems([
            ['account_code' => '154', 'project_id' => $this->project1->id, 'amount' => 5_000_000, 'vat_rate' => 10, 'tax_amount' => 500_000],
            ['account_code' => '154', 'project_id' => $this->project2->id, 'amount' => 3_000_000, 'vat_rate' => 10, 'tax_amount' => 300_000],
        ]);

        $this->service->transition($inv, PurchaseInvoiceStatus::Valid);

        $je = JournalEntry::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $inv->id)->first();

        $this->assertNotNull($je);

        $lineCount = JournalEntryLine::where('journal_entry_id', $je->id)->count();
        $this->assertEquals(4, $lineCount, '2 debit items + 1 VAT + 1 credit = 4 lines');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeInvoiceWithItems(array $items): PurchaseInvoice
    {
        $po  = $this->makePo();
        $sub = collect($items)->sum('amount');
        $tax = collect($items)->sum('tax_amount');

        $inv = PurchaseInvoice::create([
            'code'              => 'HD-NCC-' . uniqid(),
            'purchase_order_id' => $po->id,
            'supplier_id'       => $this->supplier->id,
            'subtotal'          => $sub,
            'tax_amount'        => $tax,
            'total'             => $sub + $tax,
            'paid_amount'       => 0,
            'status'            => PurchaseInvoiceStatus::Reviewing,
            'invoice_type'      => PurchaseInvoiceType::ProjectConstruction,
            'invoice_date'      => '2026-06-15',
            'created_by'        => $this->user->id,
        ]);

        foreach ($items as $i => $item) {
            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $inv->id,
                'account_code'        => $item['account_code'],
                'project_id'          => $item['project_id'] ?? null,
                'amount'              => $item['amount'],
                'vat_rate'            => $item['vat_rate'] ?? 0,
                'tax_amount'          => $item['tax_amount'] ?? 0,
                'sort_order'          => $i,
            ]);
        }

        return $inv;
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
}
