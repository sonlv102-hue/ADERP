<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectWipEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Chi phí PS — Hình thức ghi nhận (payment_method) v2:
 *
 * TC1:  payable (Ghi công nợ NCC) + NCC → Nợ154/Có3311, WIP ngay
 * TC2:  cash (Chi tiền mặt) + Fund → Nợ154/Có1111, WIP ngay
 * TC3:  bank (Chi ngân hàng) + BankAccount → Nợ154/Có1121, WIP ngay
 * TC4:  advance (Quyết toán tạm ứng) + Employee → Nợ154/Có141, WIP ngay
 * TC5:  salary (Ghi nhận nhân công) → Nợ154/Có3341, WIP ngay, không cần NCC
 * TC6:  misc (Ghi nhận khác) → Nợ154/Có3388, WIP ngay
 * TC7:  payable nhưng không có supplier → validate error
 * TC8:  TK Nợ 6237 (nâng cao) → không tạo WIP ngay, hiện KC button
 * TC9:  Kết chuyển Nợ6237→Nợ154 → tạo JE N154/C6237, WIP tạo sau
 * TC10: audit-journals command phát hiện J3 (3311 thiếu supplier)
 */
class ProjectExpenseModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Supplier $supplier;
    private Employee $employee;
    private Fund $fund;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@mode-test.local'],
            ['name' => 'Admin Mode', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        foreach ([
            ['code' => '154',  'name' => 'WIP',           'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT',     'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền mặt',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1121', 'name' => 'TG ngân hàng',  'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'Phải trả NCC',  'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3341', 'name' => 'Phải trả NV',   'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3388', 'name' => 'Phải trả khác', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '141',  'name' => 'Tạm ứng',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6237', 'name' => 'CP máy TC',     'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $customer = \App\Models\Customer::create(['code' => 'KH-MODE', 'name' => 'KH Mode', 'phone' => '0900000099']);
        $this->project = Project::create([
            'code' => 'DA-MODE', 'name' => 'Dự án mode test', 'status' => 'in_progress', 'customer_id' => $customer->id,
        ]);

        $this->supplier = Supplier::create([
            'code' => 'NCC-MODE', 'name' => 'NCC Mode', 'phone' => '0900000002', 'is_active' => true,
        ]);
        $this->employee = Employee::create([
            'code' => 'NV-MODE', 'name' => 'NV Mode', 'status' => 'active', 'hire_date' => '2024-01-01',
            'created_by' => $this->user->id,
        ]);
        $this->fund = Fund::create([
            'name' => 'Quỹ mode', 'code' => 'QM01', 'type' => 'cash', 'account_code' => '1111',
        ]);
        $this->bankAccount = BankAccount::create([
            'name' => 'TK Mode', 'bank_name' => 'MB Mode', 'account_number' => '1234567890', 'account_code' => '1121',
        ]);
    }

    private function addExpense(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            route('projects.projects.expenses.store', $this->project->id),
            array_merge([
                'category'       => 'labor',
                'description'    => 'Test chi phí',
                'amount'         => 1000000,
                'expense_date'   => '2026-06-21',
                'debit_account'  => '154',
                'credit_account' => '3311',
                'payment_method' => 'payable',
            ], $payload)
        );
    }

    /** TC1: payable → Nợ154/Có3311, WIP ngay */
    public function test_payable_creates_je_and_wip_immediately(): void
    {
        $this->addExpense([
            'payment_method' => 'payable',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);
        $this->assertEquals('posted', $expense->status);
        $this->assertNotNull($expense->journal_entry_id);
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $lines = $je->lines;
        $this->assertTrue($lines->contains(fn ($l) => str_starts_with($l->account_code, '154') && $l->debit > 0));
        $this->assertTrue($lines->contains(fn ($l) => str_starts_with($l->account_code, '3311') && $l->credit > 0));
    }

    /** TC2: cash → Nợ154/Có1111, WIP ngay */
    public function test_cash_payment_mode_creates_wip(): void
    {
        $this->addExpense([
            'payment_method' => 'cash',
            'credit_account' => '1111',
            'fund_id'        => $this->fund->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertEquals('posted', $expense->status);
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '1111' && $l->credit > 0));
    }

    /** TC3: bank → Nợ154/Có1121, WIP ngay */
    public function test_bank_payment_mode_creates_wip(): void
    {
        $this->addExpense([
            'payment_method'  => 'bank',
            'credit_account'  => '1121',
            'bank_account_id' => $this->bankAccount->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '1121' && $l->credit > 0));
    }

    /** TC4: advance → Nợ154/Có141, WIP ngay */
    public function test_advance_payment_mode_creates_wip(): void
    {
        $this->addExpense([
            'payment_method' => 'advance',
            'credit_account' => '141',
            'employee_id'    => $this->employee->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense->project_wip_entry_id);
        $this->assertEquals($this->employee->id, $expense->employee_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '141' && $l->credit > 0));
    }

    /** TC5: salary → Nợ154/Có3341, không cần NCC, WIP ngay */
    public function test_salary_mode_does_not_require_supplier(): void
    {
        $this->addExpense([
            'payment_method' => 'salary',
            'credit_account' => '3341',
            'supplier_id'    => null,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense->project_wip_entry_id);
        $this->assertNull($expense->supplier_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '3341' && $l->credit > 0));
    }

    /** TC6: misc → Nợ154/Có3388, WIP ngay */
    public function test_misc_mode_creates_wip_with_3388(): void
    {
        $this->addExpense([
            'payment_method' => 'misc',
            'credit_account' => '3388',
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '3388' && $l->credit > 0));
    }

    /** TC7: payable nhưng không có supplier → validate error, expense không được tạo */
    public function test_payable_without_supplier_is_rejected(): void
    {
        $this->addExpense([
            'payment_method' => 'payable',
            'credit_account' => '3311',
            'supplier_id'    => null,
        ])->assertRedirect();

        $this->assertEquals(0, ProjectExpense::where('project_id', $this->project->id)->count());
    }

    /** TC8: TK Nợ 6237 (nâng cao) → không tạo WIP ngay, can_transfer=true */
    public function test_non_154_debit_does_not_create_wip_immediately(): void
    {
        $this->addExpense([
            'debit_account'  => '6237',
            'credit_account' => '3311',
            'payment_method' => 'payable',
            'supplier_id'    => $this->supplier->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertEquals('posted', $expense->status);
        $this->assertNull($expense->project_wip_entry_id); // WIP chưa được tạo

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '6237' && $l->debit > 0));
    }

    /** TC9: Kết chuyển Nợ6237 → Nợ154/Có6237, WIP được tạo sau */
    public function test_transfer_to_154_creates_correct_je_and_wip(): void
    {
        // Setup: expense TK 6237
        AccountCode::firstOrCreate(['code' => '6237'], [
            'name' => 'CP máy TC', 'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true, 'is_active' => true,
        ]);

        $this->addExpense([
            'debit_account'  => '6237',
            'credit_account' => '3311',
            'payment_method' => 'payable',
            'supplier_id'    => $this->supplier->id,
        ]);
        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);

        // Kết chuyển sang 154 qua batch endpoint
        $res = $this->post(
            route('projects.projects.expense-transfers-batch.store', $this->project->id),
            [
                'expense_ids'   => [$expense->id],
                'transfer_date' => '2026-06-21',
                'description'   => 'KC 154',
            ]
        );
        $res->assertRedirect()->assertSessionHas('success');

        // JE kết chuyển: Nợ 154 / Có 6237
        $transfer = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)->first();
        $this->assertNotNull($transfer);
        $this->assertEquals('154', $transfer->debit_account);
        $this->assertEquals('6237', $transfer->credit_account);
        $this->assertNotNull($transfer->project_wip_entry_id);

        $wip = ProjectWipEntry::find($transfer->project_wip_entry_id);
        $this->assertNotNull($wip);
        $this->assertEquals($expense->project_id, $wip->project_id);
    }

    /** TC10: audit-journals phát hiện J3 (Có3311 thiếu supplier) */
    public function test_audit_command_detects_missing_supplier_on_3311(): void
    {
        // Tạo JE có dòng Cr 3311 trước (audit J3 chỉ chạy khi có JE)
        $je = JournalEntry::create([
            'code'           => 'BT-AUDIT-001',
            'description'    => 'BT test audit',
            'posting_date'   => '2026-06-21',
            'entry_date'     => '2026-06-21',
            'status'         => 'posted',
            'reference_type' => ProjectExpense::class,
            'reference_id'   => 999,
            'is_auto'        => true,
            'created_by'     => $this->user->id,
        ]);
        \App\Models\JournalEntryLine::create([
            'journal_entry_id' => $je->id, 'account_code' => '154',  'debit' => 500000, 'credit' => 0,
            'description' => 'test', 'project_id' => $this->project->id,
        ]);
        \App\Models\JournalEntryLine::create([
            'journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0, 'credit' => 500000,
            'description' => 'test',
        ]);

        $expense = ProjectExpense::create([
            'project_id'       => $this->project->id,
            'category'         => 'equipment',
            'description'      => 'Chi phi thieu NCC',
            'amount'           => 500000,
            'expense_date'     => '2026-06-21',
            'debit_account'    => '154',
            'credit_account'   => '3311',
            'payment_method'   => 'payable',
            'supplier_id'      => null,
            'status'           => 'posted',
            'journal_entry_id' => $je->id,
            'created_by'       => $this->user->id,
        ]);

        $this->artisan('project-extra-costs:audit-journals', ['--project' => 'DA-MODE'])
            ->assertExitCode(1)
            ->expectsOutputToContain('J3');
    }
}
