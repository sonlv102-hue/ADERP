<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\FixedAsset;
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
 * Chi phí PS — Hình thức ghi nhận (payment_method) v2+v3:
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
 * TC11: depreciation → Nợ154/Có214, fixed_asset_id lưu được
 * TC12: depreciation via batch không có fixed_asset_id → validate error
 * TC13: insurance + credit_account=33831 → Nợ154/Có33831 (không phải 338 tổng hợp)
 * TC14: insurance via batch không có credit_account → validate error
 * TC15: payable + vat_amount → JE có Nợ1331 (thuế GTGT đầu vào)
 * TC16: misc lưu đúng contractor_name
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
    private FixedAsset $fixedAsset;

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
            ['code' => '6237',  'name' => 'CP máy TC',         'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '214',   'name' => 'Hao mòn TSCĐ',      'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33831', 'name' => 'BHXH NSDLĐ',         'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $this->fixedAsset = FixedAsset::create([
            'code'             => 'TSCD-TEST-001',
            'name'             => 'Máy thi công test',
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => 100_000_000,
            'status'           => 'active',
        ]);

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

    /** TC7: payable không có supplier → ĐƯỢC PHÉP — supplier là thông tin bổ sung, không bắt buộc */
    public function test_payable_without_supplier_is_allowed(): void
    {
        $this->addExpense([
            'payment_method' => 'payable',
            'credit_account' => '3311',
            'supplier_id'    => null,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertNotNull($expense);
        $this->assertNull($expense->supplier_id);
        $this->assertEquals('3311', $expense->credit_account);
        $this->assertEquals('posted', $expense->status);
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

    // ─── Tests for v3 payment modes ─────────────────────────────────────────────

    /** TC11: depreciation → Nợ154/Có214, fixed_asset_id stored */
    public function test_depreciation_mode_creates_wip_with_214(): void
    {
        $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'    => '2026-06-22',
                'payment_method'  => 'depreciation',
                'fixed_asset_id'  => $this->fixedAsset->id,
                'post_immediately' => true,
                'lines' => [
                    ['category' => 'equipment', 'description' => 'Khấu hao máy thi công', 'amount' => 500000, 'debit_account' => '154'],
                ],
            ]
        )->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);
        $this->assertEquals($this->fixedAsset->id, $expense->fixed_asset_id);
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '214' && $l->credit > 0));
        $this->assertFalse($je->lines->contains(fn ($l) => $l->account_code === '3311'));
    }

    /** TC12: depreciation via batch không có debit_account trong line và post_immediately → lỗi TK Nợ */
    public function test_depreciation_without_debit_account_fails_on_confirm(): void
    {
        $res = $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'     => '2026-06-22',
                'payment_method'   => 'depreciation',
                'fixed_asset_id'   => $this->fixedAsset->id,
                'post_immediately' => true,
                'lines' => [
                    // no debit_account → confirm pre-check catches it
                    ['category' => 'equipment', 'description' => 'KH TSCĐ', 'amount' => 500000],
                ],
            ]
        );

        $res->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(0, ProjectExpense::where('project_id', $this->project->id)->count());
    }

    /** TC12b: depreciation không có fixed_asset_id (chỉ metadata) → cho phép lưu nháp */
    public function test_depreciation_without_fixed_asset_saves_draft(): void
    {
        $res = $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'     => '2026-06-22',
                'payment_method'   => 'depreciation',
                'post_immediately' => false,
                'lines' => [
                    ['category' => 'equipment', 'description' => 'KH TSCĐ', 'amount' => 500000],
                ],
            ]
        );

        $res->assertRedirect()->assertSessionHas('success');
        $this->assertEquals(1, ProjectExpense::where('project_id', $this->project->id)->count());
        $this->assertEquals('draft', ProjectExpense::where('project_id', $this->project->id)->first()->status);
    }

    /** TC13: insurance + credit_account=33831 → Nợ154/Có33831 (không dùng 338 tổng hợp) */
    public function test_insurance_mode_creates_wip_with_detail_338_account(): void
    {
        $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'    => '2026-06-22',
                'payment_method'  => 'insurance',
                'credit_account'  => '33831',
                'post_immediately' => true,
                'lines' => [
                    ['category' => 'labor', 'description' => 'Trích BHXH NSDLĐ', 'amount' => 300000, 'debit_account' => '154'],
                ],
            ]
        )->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);
        $this->assertEquals('33831', $expense->credit_account);
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '33831' && $l->credit > 0));
        $this->assertFalse($je->lines->contains(fn ($l) => $l->account_code === '338'));
    }

    /** TC14: insurance không có credit_account nhưng có debit_account → fallback AccountingSettings (33831) */
    public function test_insurance_without_credit_account_uses_fallback(): void
    {
        // Seed TK 33831 cần thiết cho JE
        \App\Models\AccountCode::firstOrCreate(['code' => '33831'], [
            'name' => 'BHXH NSDLĐ', 'type' => 'liability', 'normal_balance' => 'credit',
            'is_detail' => true, 'is_active' => true,
        ]);

        $res = $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'     => '2026-06-22',
                'payment_method'   => 'insurance',
                'post_immediately' => true,
                // credit_account not provided → resolveExpenseCreditAccount('insurance') returns '33831' fallback
                'lines' => [
                    ['category' => 'labor', 'description' => 'Trích BHXH', 'amount' => 300000, 'debit_account' => '154'],
                ],
            ]
        );

        $res->assertRedirect()->assertSessionHas('success');
        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertNotNull($expense);
        // Credit resolved from AccountingSettings fallback
        $this->assertEquals('33831', $expense->credit_account);
    }

    /** TC14b: batch confirm không có credit_account và không có payment_method → lỗi TK Có */
    public function test_confirm_without_credit_account_and_no_payment_method_fails(): void
    {
        $res = $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'     => '2026-06-22',
                // No payment_method → no header credit resolution
                'post_immediately' => true,
                'lines' => [
                    ['category' => 'labor', 'description' => 'Chi phí lao động', 'amount' => 300000, 'debit_account' => '154'],
                    // no credit_account in line, no header credit
                ],
            ]
        );

        $res->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(0, ProjectExpense::where('project_id', $this->project->id)->count());
    }

    /** TC15: payable + vat_amount → JE có thêm Nợ 1331 */
    public function test_payable_with_vat_creates_1331_debit(): void
    {
        $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'    => '2026-06-22',
                'payment_method'  => 'payable',
                'supplier_id'     => $this->supplier->id,
                'post_immediately' => true,
                'lines' => [
                    [
                        'category'       => 'equipment',
                        'description'    => 'Mua vật liệu có VAT',
                        'amount'         => 1000000,
                        'vat_rate'       => 10,
                        'vat_amount'     => 100000,
                        'has_vat_invoice' => true,
                        'debit_account'  => '154',
                    ],
                ],
            ]
        )->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);
        $this->assertEquals(100000, $expense->vat_amount);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '1331' && $l->debit > 0));
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '3311' && $l->credit >= 1100000));
    }

    /** TC16: misc lưu đúng contractor_name và không cần supplier */
    public function test_misc_stores_contractor_info(): void
    {
        $this->post(
            route('projects.projects.expenses.batch', $this->project->id),
            [
                'expense_date'      => '2026-06-22',
                'payment_method'    => 'misc',
                'contractor_name'   => 'Đội thợ Nguyễn Văn A',
                'contractor_phone'  => '0912345678',
                'contract_number'   => 'HK-2026-001',
                'post_immediately'  => true,
                'lines' => [
                    ['category' => 'labor', 'description' => 'Tiền nhân công khoán', 'amount' => 2000000, 'debit_account' => '154'],
                ],
            ]
        )->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->latest()->first();
        $this->assertNotNull($expense);
        $this->assertNull($expense->supplier_id);
        $this->assertEquals('Đội thợ Nguyễn Văn A', $expense->contractor_name);
        $this->assertEquals('0912345678', $expense->contractor_phone);
        $this->assertEquals('HK-2026-001', $expense->contract_number);

        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertTrue($je->lines->contains(fn ($l) => $l->account_code === '3388' && $l->credit > 0));
    }

    // ─── End v3 tests ────────────────────────────────────────────────────────────

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
