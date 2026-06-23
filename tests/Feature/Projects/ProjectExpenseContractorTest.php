<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Contractor / supplier_id rule / has_vat_invoice logic.
 *
 * TC1: freelance_contractor + misc, không có supplier_id → OK
 * TC2: freelance_contractor + cash, không có supplier_id → OK, cần fund_id
 * TC3: subcontractor_invoice + payable, có supplier_id + VAT → OK, N154/N1331/C3311
 * TC4: subcontractor_invoice + misc (không có supplier_id) → validation fail
 * TC5: credit_account=3311 mà không có supplier_id → validation fail
 * TC6: credit_account=3388 mà không có supplier_id → OK
 * TC7: freelance_contractor + has_vat_invoice=false → vat_amount bị clear về 0
 * TC8: freelance_contractor + has_vat_invoice=true → giữ nguyên VAT, tạo N1331
 * TC9: contractor_name được lưu vào expense
 */
class ProjectExpenseContractorTest extends TestCase
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
            ['email' => 'admin@contractor-test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        foreach ([
            ['code' => '154',  'name' => 'WIP',            'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền mặt',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1121', 'name' => 'TG NH',          'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'Phải trả NCC',   'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3388', 'name' => 'Phải trả khác',  'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3341', 'name' => 'Phải trả NV',    'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3335', 'name' => 'Thuế TNCN',      'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $customer = \App\Models\Customer::create(['code' => 'KH-CT1', 'name' => 'KH Contractor', 'phone' => '0900000088']);
        $this->project = Project::create([
            'code' => 'DA-CT1', 'name' => 'Dự án contractor test', 'status' => 'in_progress', 'customer_id' => $customer->id,
        ]);
        $this->supplier = Supplier::create([
            'code' => 'NCC-CT1', 'name' => 'NCC Contractor', 'phone' => '0900000022', 'is_active' => true,
        ]);
        $this->fund = Fund::create([
            'name' => 'Quỹ CT', 'code' => 'QCT1', 'type' => 'cash', 'account_code' => '1111',
        ]);
        $this->bankAccount = BankAccount::create([
            'name' => 'TK CT', 'bank_name' => 'MB CT', 'account_number' => '888777666', 'account_code' => '1121',
        ]);
    }

    private function addExpense(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            route('projects.projects.expenses.store', $this->project->id),
            array_merge([
                'category'       => 'labor',
                'description'    => 'Test contractor',
                'amount'         => 5000000,
                'expense_date'   => '2026-06-22',
                'debit_account'  => '154',
                'credit_account' => '3388',
                'payment_method' => 'misc',
            ], $payload)
        );
    }

    /** TC1: freelance_contractor + misc, không supplier_id → OK */
    public function test_freelance_misc_no_supplier_allowed(): void
    {
        $this->addExpense([
            'labor_type'     => 'freelance_contractor',
            'payment_method' => 'misc',
            'credit_account' => '3388',
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertNotNull($expense);
        $this->assertNull($expense->supplier_id, 'freelance_contractor không cần supplier_id');
        $this->assertNotNull($expense->project_wip_entry_id, 'WIP phải được tạo');

        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $this->assertNotNull($je);
        $lines = $je->lines;
        $this->assertNotNull($lines->firstWhere('account_code', '3388'), 'Có 3388 khi misc');
        $this->assertNull($lines->firstWhere('account_code', '3311'), 'Không có 3311');
    }

    /** TC2: freelance_contractor + cash + fund_id, không supplier_id → OK */
    public function test_freelance_cash_no_supplier_allowed(): void
    {
        $this->addExpense([
            'labor_type'     => 'freelance_contractor',
            'payment_method' => 'cash',
            'credit_account' => '1111',
            'fund_id'        => $this->fund->id,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertNull($expense->supplier_id);
        $this->assertNotNull($expense->project_wip_entry_id);
    }

    /** TC3: subcontractor_invoice + payable + supplier_id + VAT → OK */
    public function test_subcontractor_invoice_with_supplier_and_vat(): void
    {
        $this->addExpense([
            'labor_type'     => 'subcontractor_invoice',
            'payment_method' => 'payable',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
            'amount'         => 10000000,
            'vat_rate'       => 10,
            'vat_amount'     => 1000000,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertEquals($this->supplier->id, $expense->supplier_id);
        $this->assertNotNull($expense->project_wip_entry_id);

        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = $je->lines;
        $this->assertEquals(3, $lines->count(), 'N154 + N1331 + C3311');
        $this->assertNotNull($lines->firstWhere('account_code', '154'));
        $this->assertNotNull($lines->firstWhere('account_code', '1331'));
        $this->assertNotNull($lines->firstWhere('account_code', '3311'));
    }

    /** TC4: subcontractor_invoice + misc, không supplier_id → GHI NHẬN ĐƯỢC — supplier là thông tin bổ sung */
    public function test_subcontractor_invoice_without_supplier_is_allowed(): void
    {
        $this->addExpense([
            'labor_type'     => 'subcontractor_invoice',
            'payment_method' => 'misc',
            'credit_account' => '3388',
            // supplier_id omitted — no longer required
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertEquals(1, ProjectExpense::where('project_id', $this->project->id)->count());
        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertNull($expense->supplier_id);
    }

    /** TC5: credit_account=3311 không có supplier_id → GHI NHẬN ĐƯỢC — supplier advisory only */
    public function test_credit_3311_without_supplier_is_allowed(): void
    {
        $this->addExpense([
            'payment_method' => 'payable',
            'credit_account' => '3311',
            // supplier_id omitted — no longer required
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertEquals(1, ProjectExpense::where('project_id', $this->project->id)->count());
    }

    /** TC6: credit_account=3388 mà không có supplier_id → OK */
    public function test_credit_3388_without_supplier_allowed(): void
    {
        $this->addExpense([
            'payment_method' => 'misc',
            'credit_account' => '3388',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertEquals(1, ProjectExpense::where('project_id', $this->project->id)->count());
    }

    /** TC7: freelance_contractor + has_vat_invoice=false → vat_amount bị clear về 0 */
    public function test_freelance_no_vat_invoice_clears_vat(): void
    {
        $this->addExpense([
            'labor_type'      => 'freelance_contractor',
            'payment_method'  => 'misc',
            'credit_account'  => '3388',
            'has_vat_invoice' => false,
            'vat_rate'        => 10,
            'vat_amount'      => 500000, // user nhập nhưng sẽ bị clear
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertEquals(0, $expense->vat_amount, 'VAT phải bị clear khi freelance + no has_vat_invoice');
        $this->assertNull($expense->vat_rate, 'VAT rate phải bị null');

        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = $je->lines;
        $this->assertCount(2, $lines, 'Chỉ 2 dòng: N154 + C3388, không có N1331');
        $this->assertNull($lines->firstWhere('account_code', '1331'), 'Không có dòng 1331');
    }

    /** TC8: freelance_contractor + has_vat_invoice=true → giữ VAT, tạo N1331 */
    public function test_freelance_with_vat_invoice_keeps_vat(): void
    {
        $this->addExpense([
            'labor_type'      => 'freelance_contractor',
            'payment_method'  => 'misc',
            'credit_account'  => '3388',
            'has_vat_invoice' => true,
            'vat_rate'        => 10,
            'vat_amount'      => 500000,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertTrue((bool) $expense->has_vat_invoice);
        $this->assertEquals(500000, $expense->vat_amount, 'VAT phải được giữ khi has_vat_invoice=true');

        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = $je->lines;
        $this->assertNotNull($lines->firstWhere('account_code', '1331'), 'Phải có dòng Nợ 1331');
        $this->assertEquals(500000, (int) $lines->firstWhere('account_code', '1331')->debit);
    }

    /** TC9: contractor_name được lưu vào expense */
    public function test_contractor_name_is_stored(): void
    {
        $this->addExpense([
            'labor_type'               => 'freelance_contractor',
            'payment_method'           => 'misc',
            'credit_account'           => '3388',
            'contractor_name'          => 'Đội thợ Nguyễn Văn A',
            'contractor_representative'=> 'Nguyễn Văn A',
            'contractor_phone'         => '0909111222',
            'contractor_id_number'     => '036111222333',
            'contract_number'          => 'HĐK-2026-001',
        ])->assertRedirect()->assertSessionHas('success');

        $expense = ProjectExpense::where('project_id', $this->project->id)->first();
        $this->assertEquals('Đội thợ Nguyễn Văn A', $expense->contractor_name);
        $this->assertEquals('Nguyễn Văn A', $expense->contractor_representative);
        $this->assertEquals('0909111222', $expense->contractor_phone);
        $this->assertEquals('036111222333', $expense->contractor_id_number);
        $this->assertEquals('HĐK-2026-001', $expense->contract_number);
    }
}
