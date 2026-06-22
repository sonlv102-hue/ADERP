<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * LaborType + PIT split + insurance allocation.
 *
 * TC1: internal_employee + salary → N154/C3341, WIP tạo ngay
 * TC2: freelance_contractor + cash, không PIT → N154/C1111, WIP
 * TC3: freelance_contractor + bank, không PIT → N154/C1121, WIP
 * TC4: freelance_contractor + cash, PIT 10%, 20.000.000 → N154 20M, C1111 18M, C3335 2M, WIP 20M
 * TC5: freelance_contractor + bank, PIT 10%, 5.000.000 → N154 5M, C1121 4.5M, C3335 0.5M, WIP 5M
 * TC6: freelance_contractor + misc → N154/C3388, không split PIT
 * TC7: subcontractor_invoice + VAT 10%, 10.000.000 → N154 10M, N1331 1M, C3311 11M, WIP=10M
 * TC8: insurance_allocation + TK 33831 → N154/C33831, WIP
 * TC9: TK 334 tổng (is_detail=false) → bị từ chối
 * TC10: TK 338 tổng (is_detail=false) → bị từ chối
 */
class ProjectExpenseLaborTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Supplier $supplier;
    private Fund $fund;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@labor-test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        foreach ([
            ['code' => '154',   'name' => 'WIP',           'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1111',  'name' => 'Tiền mặt',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1121',  'name' => 'TG NH',         'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1331',  'name' => 'Thuế GTGT',     'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311',  'name' => 'Phải trả NCC',  'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3335',  'name' => 'Thuế TNCN',     'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3341',  'name' => 'Phải trả NV',   'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3388',  'name' => 'Phải trả khác', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33831', 'name' => 'BHXH DN',       'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33841', 'name' => 'BHYT DN',       'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3385',  'name' => 'BHTN',          'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33821', 'name' => 'KPCĐ DN',       'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            // TK tổng hợp để test reject
            ['code' => '334',   'name' => 'Phải trả NV (TH)', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => false],
            ['code' => '338',   'name' => 'Phải trả khác (TH)', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => false],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $customer = \App\Models\Customer::create(['code' => 'KH-LBT', 'name' => 'KH Labor', 'phone' => '0900000099']);
        $this->project = Project::create([
            'code' => 'DA-LBT', 'name' => 'Dự án labor test', 'status' => 'in_progress', 'customer_id' => $customer->id,
        ]);

        $this->supplier = Supplier::create([
            'code' => 'NCC-LBT', 'name' => 'NCC Labor', 'phone' => '0900000011', 'is_active' => true,
        ]);
        $this->fund = Fund::create([
            'name' => 'Quỹ labor', 'code' => 'QLB1', 'type' => 'cash', 'account_code' => '1111',
        ]);
        $this->bankAccount = BankAccount::create([
            'name' => 'TK Labor', 'bank_name' => 'MB Labor', 'account_number' => '999888777', 'account_code' => '1121',
        ]);
    }

    private function addExpense(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            route('projects.projects.expenses.store', $this->project->id),
            array_merge([
                'category'       => 'labor',
                'description'    => 'Test labor',
                'amount'         => 1000000,
                'expense_date'   => '2026-06-21',
                'debit_account'  => '154',
                'credit_account' => '3341',
                'payment_method' => 'salary',
            ], $payload)
        );
    }

    private function assertJeLines(ProjectExpense $expense): \Illuminate\Support\Collection
    {
        $je = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)->firstOrFail();
        $this->assertNotNull($je, 'JE phải tồn tại');
        $this->assertEquals((int) $je->lines->sum('debit'), (int) $je->lines->sum('credit'), 'JE phải cân');
        return $je->lines;
    }

    /** TC1: internal_employee + salary → N154/C3341, WIP tạo ngay */
    public function test_internal_employee_salary_mode(): void
    {
        $this->addExpense([
            'labor_type'     => 'internal_employee',
            'payment_method' => 'salary',
            'credit_account' => '3341',
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertEquals('internal_employee', $expense->labor_type->value);
        $this->assertNotNull($expense->project_wip_entry_id, 'WIP phải tạo ngay khi N154');

        $lines = $this->assertJeLines($expense);
        $this->assertNotNull($lines->firstWhere('account_code', '154'));
        $this->assertNotNull($lines->firstWhere('account_code', '3341'));
        $this->assertNull($lines->firstWhere('account_code', '334'), 'Không được dùng TK334 tổng');
    }

    /** TC2: freelance_contractor + cash, không PIT → N154/C1111, WIP */
    public function test_freelance_cash_no_pit(): void
    {
        $this->addExpense([
            'labor_type'     => 'freelance_contractor',
            'payment_method' => 'cash',
            'credit_account' => '1111',
            'fund_id'        => $this->fund->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertFalse((bool) $expense->pit_withholding_enabled);
        $this->assertEquals(0, $expense->pit_amount);
        $this->assertNotNull($expense->project_wip_entry_id);

        $lines = $this->assertJeLines($expense);
        $this->assertCount(2, $lines, 'Chỉ 2 dòng: N154, C1111');
        $this->assertNull($lines->firstWhere('account_code', '3335'), 'Không có dòng 3335 khi không PIT');
    }

    /** TC3: freelance_contractor + bank, không PIT → N154/C1121, WIP */
    public function test_freelance_bank_no_pit(): void
    {
        $this->addExpense([
            'labor_type'      => 'freelance_contractor',
            'payment_method'  => 'bank',
            'credit_account'  => '1121',
            'bank_account_id' => $this->bankAccount->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertNotNull($expense->project_wip_entry_id);

        $lines = $this->assertJeLines($expense);
        $this->assertNotNull($lines->firstWhere('account_code', '1121'));
        $this->assertNull($lines->firstWhere('account_code', '3335'));
    }

    /** TC4: freelance_contractor + cash, PIT 10%, amount=20.000.000 */
    public function test_freelance_cash_with_pit_10_percent(): void
    {
        $this->addExpense([
            'labor_type'              => 'freelance_contractor',
            'amount'                  => 20000000,
            'payment_method'          => 'cash',
            'credit_account'          => '1111',
            'fund_id'                 => $this->fund->id,
            'pit_withholding_enabled' => true,
            'pit_rate'                => 10,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertTrue((bool) $expense->pit_withholding_enabled);
        $this->assertEquals(2000000, $expense->pit_amount);
        $this->assertEquals(18000000, $expense->net_payment_amount);
        $this->assertNotNull($expense->project_wip_entry_id);

        $wip = ProjectWipEntry::find($expense->project_wip_entry_id);
        $this->assertEquals(20000000, $wip->amount, 'WIP = tổng tiền khoán trước PIT');

        $lines = $this->assertJeLines($expense);
        $this->assertCount(3, $lines, 'N154 + C1111(net) + C3335(pit)');

        $dr154  = $lines->firstWhere('account_code', '154');
        $cr1111 = $lines->firstWhere('account_code', '1111');
        $cr3335 = $lines->firstWhere('account_code', '3335');

        $this->assertNotNull($dr154, 'Phải có Nợ 154');
        $this->assertNotNull($cr1111, 'Phải có Có 1111');
        $this->assertNotNull($cr3335, 'Phải có Có 3335');

        $this->assertEquals(20000000, (int) $dr154->debit);
        $this->assertEquals(18000000, (int) $cr1111->credit, 'Số thực trả');
        $this->assertEquals(2000000,  (int) $cr3335->credit, 'Thuế TNCN');
    }

    /** TC5: freelance_contractor + bank, PIT 10%, amount=5.000.000 */
    public function test_freelance_bank_with_pit_10_percent(): void
    {
        $this->addExpense([
            'labor_type'              => 'freelance_contractor',
            'amount'                  => 5000000,
            'payment_method'          => 'bank',
            'credit_account'          => '1121',
            'bank_account_id'         => $this->bankAccount->id,
            'pit_withholding_enabled' => true,
            'pit_rate'                => 10,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertEquals(500000,  $expense->pit_amount);
        $this->assertEquals(4500000, $expense->net_payment_amount);

        $lines = $this->assertJeLines($expense);
        $cr1121 = $lines->firstWhere('account_code', '1121');
        $cr3335 = $lines->firstWhere('account_code', '3335');
        $this->assertEquals(4500000, (int) $cr1121->credit);
        $this->assertEquals(500000,  (int) $cr3335->credit);
    }

    /** TC6: freelance_contractor + misc → N154/C3388, không split PIT */
    public function test_freelance_misc_no_pit_split(): void
    {
        $this->addExpense([
            'labor_type'              => 'freelance_contractor',
            'payment_method'          => 'misc',
            'credit_account'          => '3388',
            'pit_withholding_enabled' => true,
            'pit_rate'                => 10,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertEquals(0, $expense->pit_amount, 'PIT amount = 0 khi misc (server recalc)');

        $lines = $this->assertJeLines($expense);
        $this->assertCount(2, $lines, 'misc: 2 dòng N154/C3388, không split');
        $this->assertNull($lines->firstWhere('account_code', '3335'), 'Không có dòng 3335 khi misc');
    }

    /** TC7: subcontractor_invoice + VAT 10%, 10.000.000 */
    public function test_subcontractor_invoice_with_vat(): void
    {
        $this->addExpense([
            'labor_type'     => 'subcontractor_invoice',
            'amount'         => 10000000,
            'payment_method' => 'payable',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
            'vat_rate'       => 10,
            'vat_amount'     => 1000000,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertNotNull($expense->project_wip_entry_id);

        $wip = ProjectWipEntry::find($expense->project_wip_entry_id);
        $this->assertEquals(10000000, $wip->amount, 'WIP = tiền trước VAT, không gồm VAT');

        $lines = $this->assertJeLines($expense);
        $this->assertCount(3, $lines, 'N154 + N1331 + C3311');

        $dr154  = $lines->firstWhere('account_code', '154');
        $dr1331 = $lines->firstWhere('account_code', '1331');
        $cr3311 = $lines->firstWhere('account_code', '3311');

        $this->assertEquals(10000000, (int) $dr154->debit);
        $this->assertEquals(1000000,  (int) $dr1331->debit);
        $this->assertEquals(11000000, (int) $cr3311->credit);
    }

    /** TC8: insurance_allocation + TK 33831 (BHXH DN) → N154/C33831, WIP */
    public function test_insurance_allocation_bhxh(): void
    {
        $this->addExpense([
            'labor_type'     => 'insurance_allocation',
            'payment_method' => 'misc',
            'credit_account' => '33831',
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::first();
        $this->assertEquals('insurance_allocation', $expense->labor_type->value);
        $this->assertNotNull($expense->project_wip_entry_id);

        $lines = $this->assertJeLines($expense);
        $cr33831 = $lines->firstWhere('account_code', '33831');
        $this->assertNotNull($cr33831, 'Phải có dòng Có 33831');
        $this->assertNull($lines->firstWhere('account_code', '3388'), 'Không dùng TK3388 khi có override credit');
    }

    /** TC9: credit_account = TK 334 tổng hợp (is_detail=false) → bị từ chối */
    public function test_parent_tk334_is_rejected(): void
    {
        $this->addExpense([
            'labor_type'     => 'internal_employee',
            'payment_method' => 'salary',
            'credit_account' => '334',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertEquals(0, ProjectExpense::where('project_id', $this->project->id)->where('status', 'posted')->count(),
            'Không được tạo expense với TK334 tổng hợp');
    }

    /** TC10: credit_account = TK 338 tổng hợp (is_detail=false) → bị từ chối */
    public function test_parent_tk338_is_rejected(): void
    {
        $this->addExpense([
            'labor_type'     => 'insurance_allocation',
            'payment_method' => 'misc',
            'credit_account' => '338',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertEquals(0, ProjectExpense::where('project_id', $this->project->id)->where('status', 'posted')->count(),
            'Không được tạo expense với TK338 tổng hợp');
    }
}
