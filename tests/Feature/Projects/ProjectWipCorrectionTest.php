<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectWipCorrectionLog;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\StockExit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ProjectWipCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TC1: Hủy WIP entry có JE → tạo JE đảo, status=cancelled
 * TC2: Hủy WIP entry StockExit source → bị chặn
 * TC3: Chuyển WIP sang dự án khác → tạo reclass JE, entry mới ở target project
 * TC4: Chuyển sang cùng dự án → lỗi
 * TC5: Điều chỉnh tài khoản (reclass) → JE Dr target / Cr 154
 * TC6: Không cho user không có quyền thực hiện
 * TC7: wipTotal chỉ tính active entries
 */
class ProjectWipCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Project $project2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $customer = Customer::create(['code' => 'KH-T01', 'name' => 'KH Test', 'phone' => '0900000001']);
        $this->project  = Project::create([
            'code' => 'DA-TEST1', 'name' => 'Dự án test 1',
            'status' => 'in_progress', 'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->project2 = Project::create([
            'code' => 'DA-TEST2', 'name' => 'Dự án test 2',
            'status' => 'in_progress', 'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        foreach (['154', '6422', '632', '6271', '1561', '3311'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }
    }

    private function makeWipEntry(array $overrides = []): ProjectWipEntry
    {
        return ProjectWipEntry::create(array_merge([
            'project_id'  => $this->project->id,
            'source_type' => 'App\\Models\\ProjectExpense',
            'source_id'   => 1,
            'cost_type'   => 'labor',
            'amount'      => 5_000_000,
            'description' => 'Chi phí nhân công test',
            'entry_date'  => now()->toDateString(),
            'created_by'  => $this->user->id,
            'status'      => 'active',
        ], $overrides));
    }

    private function makePostedJe(): JournalEntry
    {
        $je = JournalEntry::create([
            'code'        => 'BT-TEST-' . uniqid(),
            'description' => 'Test JE',
            'entry_date'  => now()->toDateString(),
            'status'      => 'posted',
            'is_auto'     => false,
            'posted_at'   => now(),
            'created_by'  => $this->user->id,
        ]);
        JournalEntryLine::create(['journal_entry_id' => $je->id, 'account_code' => '154',  'debit' => 5_000_000, 'credit' => 0,         'description' => 'Nợ 154',  'sort_order' => 1]);
        JournalEntryLine::create(['journal_entry_id' => $je->id, 'account_code' => '6271', 'debit' => 0,         'credit' => 5_000_000, 'description' => 'Có 6271', 'sort_order' => 2]);
        return $je;
    }

    // TC1: Hủy WIP entry có JE → tạo JE đảo, status=cancelled
    public function test_tc1_cancel_wip_entry_with_je_creates_reversal(): void
    {
        $je    = $this->makePostedJe();
        $entry = $this->makeWipEntry(['journal_entry_id' => $je->id]);

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry.lines', 'project');
        $log = $service->cancelEntry($entry, 'Hạch toán nhầm dự án');

        $entry->refresh();
        $this->assertEquals('cancelled', $entry->status);
        $this->assertEquals('Hạch toán nhầm dự án', $entry->cancel_reason);
        $this->assertNotNull($entry->cancelled_at);

        $this->assertDatabaseHas('project_wip_correction_logs', [
            'wip_entry_id' => $entry->id,
            'action_type'  => 'cancel',
        ]);

        // Reversal JE should exist
        $this->assertNotNull($log->correction_je_id);
        $reversalJe = JournalEntry::find($log->correction_je_id);
        $this->assertNotNull($reversalJe);
        $this->assertEquals('posted', $reversalJe->status);

        // Reversal lines: 154 should now be on credit side
        $reversalLines = $reversalJe->lines->keyBy('account_code');
        $this->assertGreaterThan(0, (int) $reversalLines['154']->credit);
        $this->assertGreaterThan(0, (int) $reversalLines['6271']->debit);
    }

    // TC2: Hủy WIP entry StockExit source → bị chặn
    public function test_tc2_cancel_stock_exit_wip_is_blocked(): void
    {
        $entry = $this->makeWipEntry(['source_type' => StockExit::class]);

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry', 'project');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/phiếu xuất kho/i');
        $service->cancelEntry($entry, 'test');
    }

    // TC3: Chuyển WIP sang dự án khác → JE reclass + entry mới ở target
    public function test_tc3_transfer_creates_reclass_je_and_new_wip_entry(): void
    {
        $entry = $this->makeWipEntry();

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('project');
        $log = $service->transferToProject($entry, $this->project2, 'Sai dự án');

        $entry->refresh();
        $this->assertEquals('transferred', $entry->status);

        // New WIP entry on target project
        $this->assertNotNull($log->new_wip_entry_id);
        $newEntry = ProjectWipEntry::find($log->new_wip_entry_id);
        $this->assertEquals($this->project2->id, $newEntry->project_id);
        $this->assertEquals(5_000_000, (int) $newEntry->amount);
        $this->assertEquals('active', $newEntry->status);
        $this->assertEquals($entry->id, $newEntry->correction_of_id);

        // Reclass JE should be posted
        $this->assertNotNull($log->correction_je_id);
        $je = JournalEntry::find($log->correction_je_id);
        $this->assertEquals('posted', $je->status);

        // Lines: Dr 154[project2] / Cr 154[project1]
        $lines = $je->lines;
        $this->assertCount(2, $lines);
        $debitLine  = $lines->where('debit', '>', 0)->first();
        $creditLine = $lines->where('credit', '>', 0)->first();
        $this->assertEquals($this->project2->id, $debitLine->project_id);
        $this->assertEquals($this->project->id, $creditLine->project_id);
    }

    // TC4: Chuyển sang cùng dự án → lỗi
    public function test_tc4_transfer_to_same_project_throws(): void
    {
        $entry = $this->makeWipEntry();
        $entry->load('project');

        $service = app(ProjectWipCorrectionService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/khác dự án/i');
        $service->transferToProject($entry, $this->project, 'test');
    }

    // TC5: Điều chỉnh tài khoản → JE Dr 6422 / Cr 154
    public function test_tc5_reclass_account_creates_adjustment_je(): void
    {
        $entry = $this->makeWipEntry();
        $entry->load('project');

        $service = app(ProjectWipCorrectionService::class);
        $log = $service->reclassAccount($entry, '6422', 'Chi phí không thuộc DA');

        $entry->refresh();
        $this->assertEquals('adjusted', $entry->status);

        $je = JournalEntry::find($log->correction_je_id);
        $this->assertEquals('posted', $je->status);

        $lines = $je->lines->keyBy('account_code');
        $this->assertEquals(5_000_000, (int) $lines['6422']->debit);
        $this->assertEquals(5_000_000, (int) $lines['154']->credit);

        $this->assertDatabaseHas('project_wip_correction_logs', [
            'wip_entry_id' => $entry->id,
            'action_type'  => 'reclass',
            'to_account'   => '6422',
        ]);
    }

    // TC6: Không cho user không có quyền (sử dụng Spatie permission check trực tiếp)
    public function test_tc6_user_without_permission_cannot_call_service(): void
    {
        Permission::firstOrCreate(['name' => 'project.wip.adjust', 'guard_name' => 'web']);
        $noPermUser = User::firstOrCreate(
            ['email' => 'noperm@test.local'],
            ['name' => 'No Perm', 'password' => bcrypt('pass'), 'is_active' => true]
        );

        // User không có quyền project.wip.adjust (dùng Spatie method, không qua Gate::before)
        $this->assertFalse($noPermUser->hasPermissionTo('project.wip.adjust'));

        // User được cấp quyền
        $this->user->givePermissionTo('project.wip.adjust');
        $this->assertTrue($this->user->hasPermissionTo('project.wip.adjust'));
    }

    // TC8: Hủy WIP entry không có JE → không tạo reversal, chỉ mark cancelled
    public function test_tc8_cancel_wip_without_je_marks_cancelled_no_reversal(): void
    {
        $entry = $this->makeWipEntry(); // no journal_entry_id

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry', 'project');
        $log = $service->cancelEntry($entry, 'Ghi nhầm, chưa có bút toán');

        $entry->refresh();
        $this->assertEquals('cancelled', $entry->status);
        $this->assertEquals('Ghi nhầm, chưa có bút toán', $entry->cancel_reason);

        // Không tạo reversal JE
        $this->assertNull($log->correction_je_id);

        // Vẫn ghi correction log
        $this->assertDatabaseHas('project_wip_correction_logs', [
            'wip_entry_id' => $entry->id,
            'action_type'  => 'cancel',
            'correction_je_id' => null,
        ]);
    }

    // TC9: Preview cancel với kỳ đã khóa → period_info.is_locked = true
    public function test_tc9_preview_cancel_shows_locked_period_warning(): void
    {
        // Đổi kỳ 2026-06 sang closed
        \App\Models\AccountingPeriod::where('year', 2026)->where('month', 6)
            ->update(['status' => 'closed']);

        $je    = $this->makePostedJe();
        $entry = $this->makeWipEntry([
            'journal_entry_id' => $je->id,
            'entry_date'       => '2026-06-01',
        ]);

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry.lines', 'project');
        $preview = $service->previewCancel($entry);

        $this->assertTrue($preview['period_info']['is_locked']);
        $this->assertEquals('2026-06', $preview['period_info']['period']);
        $this->assertNotEmpty($preview['je_lines']);
    }

    // TC7: wipTotal chỉ tính active entries
    public function test_tc7_wip_total_excludes_non_active_entries(): void
    {
        ProjectWipEntry::create([
            'project_id' => $this->project->id, 'source_type' => 'App\\Models\\ProjectExpense',
            'source_id' => 1, 'cost_type' => 'material', 'amount' => 10_000_000,
            'description' => 'Active', 'entry_date' => now()->toDateString(),
            'status' => 'active', 'created_by' => $this->user->id,
        ]);
        ProjectWipEntry::create([
            'project_id' => $this->project->id, 'source_type' => 'App\\Models\\ProjectExpense',
            'source_id' => 2, 'cost_type' => 'material', 'amount' => 5_000_000,
            'description' => 'Cancelled', 'entry_date' => now()->toDateString(),
            'status' => 'cancelled', 'created_by' => $this->user->id,
        ]);
        ProjectWipEntry::create([
            'project_id' => $this->project->id, 'source_type' => 'App\\Models\\ProjectExpense',
            'source_id' => 3, 'cost_type' => 'material', 'amount' => 3_000_000,
            'description' => 'Transferred', 'entry_date' => now()->toDateString(),
            'status' => 'transferred', 'created_by' => $this->user->id,
        ]);

        $activeTotal = (int) ProjectWipEntry::where('project_id', $this->project->id)
            ->where('status', 'active')
            ->sum('amount');

        $this->assertEquals(10_000_000, $activeTotal);

        // Summary also excludes non-active
        $service = app(\App\Services\ProjectWipService::class);
        $summary = $service->getWipSummary($this->project->id);
        $materialRow = collect($summary)->firstWhere('cost_type', 'material');
        $this->assertEquals(10_000_000, (int) $materialRow['total']);
    }

    private function makePurchaseInvoiceWithItem(?int $projectId = null): array
    {
        $supplier  = Supplier::firstOrCreate(['code' => 'NCC-WIP-T1'], ['name' => 'NCC WIP Test', 'phone' => '0900000099']);
        $warehouse = Warehouse::firstOrCreate(['code' => 'KHO-WIP'], ['name' => 'Kho WIP', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'code'         => 'MH-WIP-' . uniqid(),
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status'       => 'sent',
            'order_date'   => now()->toDateString(),
            'total'        => 500_000,
            'created_by'   => $this->user->id,
        ]);
        $invoice = PurchaseInvoice::create([
            'code'              => 'HD-WIP-' . uniqid(),
            'purchase_order_id' => $po->id,
            'supplier_id'       => $supplier->id,
            'invoice_date'      => now()->toDateString(),
            'status'            => 'valid',
            'total'             => 500_000,
            'subtotal'          => 500_000,
            'tax_amount'        => 0,
            'created_by'        => $this->user->id,
        ]);
        $item = PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $invoice->id,
            'description'         => 'Chi phí dự án test',
            'amount'              => 500_000,
            'project_id'          => $projectId ?? $this->project->id,
            'account_code'        => '154',
        ]);
        return [$invoice, $item];
    }

    // TC10: PurchaseInvoice-sourced WIP không có JE → cancel không tạo JE đảo
    public function test_tc10_purchase_invoice_wip_no_je_cancel_no_reversal(): void
    {
        $entry = $this->makeWipEntry([
            'source_type'    => 'App\\Models\\PurchaseInvoice',
            'source_id'      => 99,
            'source_item_id' => 99,
        ]);

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry', 'project');
        $log = $service->cancelEntry($entry, 'Nhập sai dự án');

        $entry->refresh();
        $this->assertEquals('cancelled', $entry->status);
        $this->assertEquals('Nhập sai dự án', $entry->cancel_reason);
        $this->assertNull($log->correction_je_id);
        $this->assertDatabaseHas('project_wip_correction_logs', [
            'wip_entry_id' => $entry->id,
            'action_type'  => 'cancel',
        ]);
    }

    // TC11: PurchaseInvoice WIP có JE posted → cancel tạo JE đảo
    public function test_tc11_purchase_invoice_wip_with_posted_je_cancel_creates_reversal(): void
    {
        $je    = $this->makePostedJe();
        $entry = $this->makeWipEntry([
            'source_type'      => 'App\\Models\\PurchaseInvoice',
            'source_id'        => 99,
            'source_item_id'   => 99,
            'journal_entry_id' => $je->id,
        ]);

        $service = app(ProjectWipCorrectionService::class);
        $entry->load('journalEntry.lines', 'project');
        $log = $service->cancelEntry($entry, 'Hóa đơn mua sai thông tin');

        $entry->refresh();
        $this->assertEquals('cancelled', $entry->status);
        $this->assertNotNull($log->correction_je_id);

        $reversalJe = JournalEntry::find($log->correction_je_id);
        $this->assertEquals('posted', $reversalJe->status);
        $lines = $reversalJe->lines->keyBy('account_code');
        $this->assertGreaterThan(0, (int) $lines['154']->credit);
    }

    // TC12: createFromPurchaseInvoiceItem dedup — không tạo WIP thứ hai khi gọi lại
    public function test_tc12_create_from_purchase_invoice_item_deduplicates(): void
    {
        [$invoice, $item] = $this->makePurchaseInvoiceWithItem();
        $je = $this->makePostedJe();

        $service = app(\App\Services\ProjectWipService::class);
        $service->createFromPurchaseInvoiceItem($invoice, $item, $je->id);
        $service->createFromPurchaseInvoiceItem($invoice, $item, $je->id);

        $count = ProjectWipEntry::where('source_type', 'App\\Models\\PurchaseInvoice')
            ->where('source_id', $invoice->id)
            ->where('source_item_id', $item->id)
            ->count();

        $this->assertEquals(1, $count, 'Chỉ được tạo 1 WIP entry dù gọi createFromPurchaseInvoiceItem 2 lần');
    }

    // TC13: Đã cancel WIP thì gọi createFromPurchaseInvoiceItem lại không tạo thêm
    public function test_tc13_cancelled_wip_not_recreated_by_create(): void
    {
        [$invoice, $item] = $this->makePurchaseInvoiceWithItem();

        ProjectWipEntry::create([
            'project_id'     => $this->project->id,
            'source_type'    => 'App\\Models\\PurchaseInvoice',
            'source_id'      => $invoice->id,
            'source_item_id' => $item->id,
            'cost_type'      => 'overhead',
            'amount'         => 500_000,
            'description'    => 'Test',
            'entry_date'     => now()->toDateString(),
            'status'         => 'cancelled',
            'cancel_reason'  => 'Dòng WIP sinh lỗi',
            'created_by'     => $this->user->id,
        ]);

        $je      = $this->makePostedJe();
        $service = app(\App\Services\ProjectWipService::class);
        $service->createFromPurchaseInvoiceItem($invoice, $item, $je->id);

        $total = ProjectWipEntry::where('source_type', 'App\\Models\\PurchaseInvoice')
            ->where('source_id', $invoice->id)
            ->where('source_item_id', $item->id)
            ->count();

        $this->assertEquals(1, $total, 'Không được tạo thêm WIP khi đã có dòng cancelled cùng source');
    }

    // TC14: Audit command phát hiện W6 — PI source orphan
    public function test_tc14_audit_command_detects_w6_purchase_invoice_orphan(): void
    {
        $entry = $this->makeWipEntry([
            'source_type' => 'App\\Models\\PurchaseInvoice',
            'source_id'   => 999_999, // không tồn tại
        ]);

        $this->artisan('projects:wip-audit', ['--project' => $this->project->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('W6');
    }
}
