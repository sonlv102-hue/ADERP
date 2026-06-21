<?php

namespace Tests\Feature\Projects;

use App\Enums\ExpenseCategory;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\User;
use App\Services\ProjectExtraCostTransferService;
use App\Services\ProjectWipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ProjectExtraCostTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'transfer-test@test.local'],
            ['name' => 'Transfer Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        $customer = Customer::firstOrCreate(
            ['code' => 'KH-TR01'],
            ['name' => 'KH Transfer Test', 'phone' => '0900000099']
        );
        $this->project = Project::create([
            'code'        => 'DA-TR-' . uniqid(),
            'name'        => 'Dự án Transfer Test',
            'customer_id' => $customer->id,
            'status'      => 'planning',
            'created_by'  => $this->user->id,
        ]);

        AccountingPeriod::firstOrCreate(
            ['year' => now()->year, 'month' => now()->month],
            ['status' => 'open']
        );
    }

    private function seedRequiredAccounts(): void
    {
        $accounts = [
            ['code' => '6422', 'name' => 'CP quản lý DN', 'type' => 'expense',   'is_detail' => true,  'normal_balance' => 'debit'],
            ['code' => '154',  'name' => 'CPSX dở dang',  'type' => 'asset',     'is_detail' => true,  'normal_balance' => 'debit'],
            ['code' => '3311', 'name' => 'Phải trả NCC',  'type' => 'liability', 'is_detail' => true,  'normal_balance' => 'credit'],
            ['code' => '1331', 'name' => 'Thuế GTGT đầu vào', 'type' => 'asset', 'is_detail' => true,  'normal_balance' => 'debit'],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a + ['parent_code' => null]);
        }
    }

    /** @test */
    public function expense_with_non_154_account_does_not_create_wip_directly(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'   => $this->project->id,
            'category'     => ExpenseCategory::Other->value,
            'description'  => 'Chi phí quản lý dự án',
            'amount'       => 50_000_000,
            'expense_date' => now()->toDateString(),
            'debit_account'=> '6422',
            'credit_account'=> '3311',
            'created_by'   => $this->user->id,
        ]);

        $service = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $service->createFromExpense($expense);

        // JE được tạo
        $je = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)
            ->first();
        $this->assertNotNull($je, 'JE phải được tạo');

        // WIP KHÔNG được tạo trực tiếp
        $wip = ProjectWipEntry::where('project_id', $this->project->id)
            ->where('source_type', ProjectExpense::class)
            ->where('source_id', $expense->id)
            ->first();
        $this->assertNull($wip, 'WIP không được tạo trực tiếp khi TK Nợ là 6422');

        // Nút kết chuyển sẽ hiển thị
        $service154 = app(ProjectExtraCostTransferService::class);
        $remaining = $service154->getRemainingTransferableAmount($expense);
        $this->assertEquals(50_000_000, $remaining);
    }

    /** @test */
    public function expense_with_154_account_creates_wip_directly(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí trực tiếp 154',
            'amount'        => 30_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '154',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $service = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $service->createFromExpense($expense);

        // WIP được tạo ngay
        $wip = ProjectWipEntry::where('project_id', $this->project->id)
            ->where('source_type', ProjectExpense::class)
            ->where('source_id', $expense->id)
            ->first();
        $this->assertNotNull($wip, 'WIP phải được tạo khi TK Nợ là 154');
        $this->assertEquals(30_000_000, $wip->amount);
    }

    /** @test */
    public function transfer_full_amount_to_154(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí kết chuyển',
            'amount'        => 50_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        // Tạo JE gốc trước
        $wip = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $wip->createFromExpense($expense);

        // Kết chuyển toàn bộ
        $service = app(ProjectExtraCostTransferService::class);
        $transfer = $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 50_000_000,
            'debit_account' => '154',
            'description'   => 'KC toàn bộ sang 154',
        ]);

        // Có bản ghi transfer
        $this->assertEquals('posted', $transfer->status);
        $this->assertEquals(50_000_000, $transfer->amount);
        $this->assertEquals('154', $transfer->debit_account);
        $this->assertEquals('6422', $transfer->credit_account);

        // JE kết chuyển: Nợ 154 / Có 6422
        $je = $transfer->journalEntry;
        $this->assertNotNull($je);
        $debitLine = $je->lines()->where('account_code', '154')->first();
        $creditLine = $je->lines()->where('account_code', '6422')->first();
        $this->assertEquals(50_000_000, $debitLine->debit);
        $this->assertEquals(50_000_000, $creditLine->credit);
        $this->assertEquals($this->project->id, $debitLine->project_id);

        // WIP entry được tạo
        $wipEntry = ProjectWipEntry::where('source_type', ProjectExtraCostTransfer::class)
            ->where('source_id', $transfer->id)
            ->first();
        $this->assertNotNull($wipEntry);
        $this->assertEquals(50_000_000, $wipEntry->amount);
        $this->assertEquals('active', $wipEntry->status);

        // Remaining = 0
        $remaining = $service->getRemainingTransferableAmount($expense);
        $this->assertEquals(0, $remaining);
    }

    /** @test */
    public function partial_transfer_then_second_limited_transfer(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí một phần',
            'amount'        => 50_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $wipSvc = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $wipSvc->createFromExpense($expense);

        $service = app(ProjectExtraCostTransferService::class);

        // Kết chuyển lần 1: 30M
        $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 30_000_000,
            'debit_account' => '154',
        ]);

        $this->assertEquals(20_000_000, $service->getRemainingTransferableAmount($expense));

        // Lần 2 quá số còn lại → exception
        $this->expectException(\InvalidArgumentException::class);
        $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 25_000_000, // vượt 5M
            'debit_account' => '154',
        ]);
    }

    /** @test */
    public function cancel_transfer_reverses_je_and_cancels_wip(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí cần hủy KC',
            'amount'        => 50_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $wipSvc = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $wipSvc->createFromExpense($expense);

        $service = app(ProjectExtraCostTransferService::class);
        $transfer = $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 50_000_000,
            'debit_account' => '154',
        ]);

        // Hủy kết chuyển
        $service->cancelTransfer($transfer, 'Nhầm chi phí');

        $transfer->refresh();
        $this->assertEquals('cancelled', $transfer->status);
        $this->assertNotNull($transfer->reversal_journal_entry_id);

        // Bút toán đảo: Nợ 6422 / Có 154
        $reverseJe = JournalEntry::find($transfer->reversal_journal_entry_id);
        $this->assertNotNull($reverseJe);
        $debitLine  = $reverseJe->lines()->where('account_code', '6422')->first();
        $creditLine = $reverseJe->lines()->where('account_code', '154')->first();
        $this->assertEquals(50_000_000, $debitLine->debit);
        $this->assertEquals(50_000_000, $creditLine->credit);

        // WIP entry đã hủy
        $wipEntry = ProjectWipEntry::find($transfer->project_wip_entry_id);
        $this->assertEquals('cancelled', $wipEntry->status);

        // Remaining quay về 50M
        $this->assertEquals(50_000_000, $service->getRemainingTransferableAmount($expense));
    }

    /** @test */
    public function cannot_transfer_from_forbidden_account(): void
    {
        $this->seedRequiredAccounts();
        AccountCode::firstOrCreate(['code' => '1121'], [
            'name' => 'TK ngân hàng', 'type' => 'asset', 'is_detail' => true,
            'normal_balance' => 'debit', 'parent_code' => null,
        ]);

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí TK cấm',
            'amount'        => 10_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '1121', // Không được phép
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        // Không thể tạo JE vì 1121 là TK không hợp lệ (CPDD validate),
        // nhưng service sẽ throw khi kiểm tra forbidden prefix
        $service = app(ProjectExtraCostTransferService::class);

        // Tạo JE giả bằng cách mock — thực ra cần JE để assertCanTransfer bước 3 pass
        // Thay vì mock, ta kiểm tra assertAllowedSourceAccount trực tiếp
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/không được phép/');

        $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 10_000_000,
            'debit_account' => '154',
        ]);
    }

    /** @test */
    public function cannot_transfer_vat_amount(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí có VAT',
            'amount'        => 50_000_000,   // before VAT
            'vat_amount'    => 5_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $wipSvc = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $wipSvc->createFromExpense($expense);

        $service = app(ProjectExtraCostTransferService::class);

        // Remaining dựa trên amount (before VAT), không cộng vat_amount
        $remaining = $service->getRemainingTransferableAmount($expense);
        $this->assertEquals(50_000_000, $remaining); // chỉ 50M, không 55M

        // Không cho kết chuyển vượt quá 50M
        $this->expectException(\InvalidArgumentException::class);
        $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 55_000_000, // vượt quá
            'debit_account' => '154',
        ]);
    }

    /** @test */
    public function wip_source_label_is_project_extra_cost_transfer(): void
    {
        $this->seedRequiredAccounts();

        $expense = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Chi phí WIP source type',
            'amount'        => 20_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $wipSvc = app(ProjectWipService::class);
        $expense->loadMissing('project');
        $wipSvc->createFromExpense($expense);

        $service = app(ProjectExtraCostTransferService::class);
        $transfer = $service->transferTo154($expense, [
            'transfer_date' => now()->toDateString(),
            'amount'        => 20_000_000,
            'debit_account' => '154',
        ]);

        $wipEntry = ProjectWipEntry::where('source_type', ProjectExtraCostTransfer::class)
            ->where('source_id', $transfer->id)
            ->first();

        $this->assertNotNull($wipEntry);
        $this->assertEquals(\App\Models\ProjectExtraCostTransfer::class, $wipEntry->source_type);
        $this->assertEquals($transfer->id, $wipEntry->source_id);
        $this->assertEquals('active', $wipEntry->status);
    }

    /** @test */
    public function batch_transfer_multiple_expenses_with_same_account(): void
    {
        $this->seedRequiredAccounts();

        $wipSvc  = app(ProjectWipService::class);
        $service = app(ProjectExtraCostTransferService::class);

        // Tạo 3 chi phí 6422
        $expenses = [];
        foreach ([10_000_000, 15_000_000, 8_000_000] as $amt) {
            $e = ProjectExpense::create([
                'project_id'    => $this->project->id,
                'category'      => ExpenseCategory::Other->value,
                'description'   => "CP {$amt}",
                'amount'        => $amt,
                'expense_date'  => now()->toDateString(),
                'debit_account' => '6422',
                'credit_account'=> '3311',
                'created_by'    => $this->user->id,
            ]);
            $e->loadMissing('project');
            $wipSvc->createFromExpense($e);
            $expenses[] = $e;
        }

        $transfers = $service->transferBatch($this->project, [
            'expense_ids'   => collect($expenses)->pluck('id')->toArray(),
            'transfer_date' => now()->toDateString(),
        ]);

        // 3 transfers tạo ra
        $this->assertCount(3, $transfers);

        // Mỗi transfer có JE riêng Nợ 154 / Có 6422
        foreach ($transfers as $t) {
            $this->assertEquals('posted', $t->status);
            $je = $t->journalEntry;
            $this->assertNotNull($je);
            $this->assertEquals(1, $je->lines()->where('account_code', '154')->count());
            $this->assertEquals(1, $je->lines()->where('account_code', '6422')->count());
        }

        // Tổng N154 = 33M
        $total154 = ProjectExtraCostTransfer::whereIn('id', collect($transfers)->pluck('id'))
            ->where('status', 'posted')
            ->sum('amount');
        $this->assertEquals(33_000_000, $total154);
    }

    /** @test */
    public function batch_transfer_multiple_expenses_with_different_accounts(): void
    {
        $this->seedRequiredAccounts();

        // Thêm TK 6237
        AccountCode::firstOrCreate(['code' => '6237'], [
            'name' => 'Chi phí xây dựng', 'type' => 'expense', 'is_detail' => true, 'normal_balance' => 'debit',
        ]);

        $wipSvc  = app(ProjectWipService::class);
        $service = app(ProjectExtraCostTransferService::class);

        $e1 = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'CP TK 6422',
            'amount'        => 20_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);
        $e1->loadMissing('project');
        $wipSvc->createFromExpense($e1);

        $e2 = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'CP TK 6237',
            'amount'        => 10_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6237',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);
        $e2->loadMissing('project');
        $wipSvc->createFromExpense($e2);

        $transfers = $service->transferBatch($this->project, [
            'expense_ids'   => [$e1->id, $e2->id],
            'transfer_date' => now()->toDateString(),
        ]);

        $this->assertCount(2, $transfers);

        // Transfer 1: Nợ 154 / Có 6422
        $t1 = collect($transfers)->firstWhere('credit_account', '6422');
        $this->assertNotNull($t1);
        $this->assertEquals(20_000_000, $t1->amount);

        // Transfer 2: Nợ 154 / Có 6237
        $t2 = collect($transfers)->firstWhere('credit_account', '6237');
        $this->assertNotNull($t2);
        $this->assertEquals(10_000_000, $t2->amount);
    }

    /** @test */
    public function batch_transfer_partial_amounts(): void
    {
        $this->seedRequiredAccounts();

        $wipSvc  = app(ProjectWipService::class);
        $service = app(ProjectExtraCostTransferService::class);

        $e1 = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'CP partial batch',
            'amount'        => 50_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);
        $e1->loadMissing('project');
        $wipSvc->createFromExpense($e1);

        $transfers = $service->transferBatch($this->project, [
            'expense_ids'   => [$e1->id],
            'amounts'       => [$e1->id => 20_000_000], // chỉ kết chuyển 20M
            'transfer_date' => now()->toDateString(),
        ]);

        $this->assertCount(1, $transfers);
        $this->assertEquals(20_000_000, $transfers[0]->amount);

        // Remaining = 30M
        $remaining = $service->getRemainingTransferableAmount($e1);
        $this->assertEquals(30_000_000, $remaining);
    }

    /** @test */
    public function batch_transfer_skips_expense_not_belonging_to_project(): void
    {
        $this->seedRequiredAccounts();

        // Dự án khác
        $otherProject = Project::create([
            'code'        => 'DA-OTHER-' . uniqid(),
            'name'        => 'Dự án khác',
            'customer_id' => $this->project->customer_id,
            'status'      => 'planning',
            'created_by'  => $this->user->id,
        ]);

        $foreignExpense = ProjectExpense::create([
            'project_id'    => $otherProject->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'CP dự án khác',
            'amount'        => 10_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);

        $service = app(ProjectExtraCostTransferService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->transferBatch($this->project, [
            'expense_ids'   => [$foreignExpense->id],
            'transfer_date' => now()->toDateString(),
        ]);
    }

    /** @test */
    public function preview_batch_returns_correct_structure(): void
    {
        $this->seedRequiredAccounts();

        $wipSvc  = app(ProjectWipService::class);
        $service = app(ProjectExtraCostTransferService::class);

        $e = ProjectExpense::create([
            'project_id'    => $this->project->id,
            'category'      => ExpenseCategory::Other->value,
            'description'   => 'Preview batch test',
            'amount'        => 30_000_000,
            'expense_date'  => now()->toDateString(),
            'debit_account' => '6422',
            'credit_account'=> '3311',
            'created_by'    => $this->user->id,
        ]);
        $e->loadMissing('project');
        $wipSvc->createFromExpense($e);

        $preview = $service->previewBatch($this->project, [$e->id]);

        $this->assertEquals(1, $preview['valid_count']);
        $this->assertEquals(30_000_000, $preview['total_amount']);
        $this->assertArrayHasKey('6422', $preview['credit_groups']);
        $this->assertEquals(30_000_000, $preview['credit_groups']['6422']);
        $this->assertNotEmpty($preview['je_preview']);
    }
}
