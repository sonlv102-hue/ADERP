<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Chi phí PS v2 — kiểm tra spec đầy đủ:
 *
 * TC1: Nợ 154 / Có 1111 (quỹ) → WIP + JE, journal_entry_id lưu vào expense
 * TC2: Nợ 154 / Có 3311 + VAT → JE đúng 3 dòng, WIP chỉ tiền trước VAT
 * TC3: Nợ 154 / Có 3341 (nhân công) → không cần NCC, có employee_id
 * TC4: Có 3311 không có supplier_id → validate error
 * TC5: Có 1111 không có fund_id → validate error
 * TC6: Nợ 152 → bị chặn
 * TC7: Cancel expense → JE đảo, WIP cancelled
 * TC8: Trùng số hóa đơn → flash warning_duplicate
 */
class ProjectExpenseV2Test extends TestCase
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
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        foreach ([
            ['code' => '154',  'name' => 'WIP',          'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT KT', 'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền VNĐ',     'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1121', 'name' => 'TG NH VNĐ',    'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'NCC TN',       'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '3341', 'name' => 'Lương',         'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '141',  'name' => 'Tạm ứng NV',   'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '152',  'name' => 'NVL',           'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6271', 'name' => 'CP lương',      'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6279', 'name' => 'CP khác',       'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $customer = Customer::create(['code' => 'KH-V2', 'name' => 'KH v2', 'phone' => '0900000001']);
        $this->project = Project::create([
            'code' => 'DA-V2', 'name' => 'Dự án v2 test', 'status' => 'in_progress', 'customer_id' => $customer->id,
        ]);

        $this->supplier = Supplier::create([
            'code' => 'NCC-V2', 'name' => 'NCC v2', 'phone' => '0900000002', 'payable_account_code' => '3311',
        ]);

        $this->employee = Employee::create([
            'code' => 'NV-V2', 'name' => 'NV Test', 'status' => 'active',
            'phone' => '0900000003', 'hire_date' => '2024-01-01',
            'created_by' => $this->user->id,
        ]);

        $this->fund = Fund::create([
            'code' => 'QUY-TM', 'name' => 'Quỹ tiền mặt test', 'account_code' => '1111', 'type' => 'cash',
        ]);

        $this->bankAccount = BankAccount::create([
            'name' => 'VCB test', 'bank_name' => 'VCB', 'account_number' => '1234567890', 'account_code' => '1121',
        ]);
    }

    private function store(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('projects.projects.expenses.store', $this->project), $data);
    }

    /** TC1: Nợ 154 / Có 1111 (quỹ) → WIP + JE, journal_entry_id lưu vào expense */
    public function test_debit154_credit1111_creates_wip_and_stores_je_id(): void
    {
        $this->store([
            'category'      => 'labor',
            'description'   => 'Nhân công 154',
            'amount'        => 1000000,
            'expense_date'  => '2026-06-10',
            'debit_account' => '154',
            'credit_account'=> '1111',
            'fund_id'       => $this->fund->id,
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $this->assertEquals('posted', $expense->status, 'Status phải là posted sau khi ghi nhận');
        $this->assertNotNull($expense->journal_entry_id, 'journal_entry_id phải được lưu');
        $this->assertNotNull($expense->project_wip_entry_id, 'project_wip_entry_id phải được lưu khi Nợ 154');

        // Kiểm tra JE
        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();
        $this->assertCount(2, $lines, '2 dòng: Dr154 + Cr1111');
        $this->assertEquals(1000000, $lines->sum('debit'));
        $this->assertEquals(1000000, $lines->sum('credit'));
        $this->assertNotNull($lines->firstWhere('account_code', '154'));
        $this->assertNotNull($lines->firstWhere('account_code', '1111'));

        // Kiểm tra WIP
        $wip = ProjectWipEntry::find($expense->project_wip_entry_id);
        $this->assertNotNull($wip);
        $this->assertEquals(1000000, $wip->amount, 'WIP amount = amount trước VAT');
        $this->assertEquals('active', $wip->status);
    }

    /** TC2: Nợ 154 / Có 3311 + VAT → 3 dòng JE, WIP chỉ tiền trước VAT */
    public function test_debit154_credit3311_vat_je_and_wip_preclude_vat(): void
    {
        $this->store([
            'category'       => 'equipment',
            'description'    => 'Thuê máy cho DA',
            'amount'         => 2000000,
            'vat_amount'     => 200000,
            'vat_rate'       => 10,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::find($expense->journal_entry_id);
        $this->assertNotNull($je);
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        $this->assertCount(3, $lines, '3 dòng: Dr154, Dr1331, Cr3311');
        $this->assertEquals(2200000, $lines->sum('debit'));
        $this->assertEquals(2200000, $lines->sum('credit'));

        $dr154  = $lines->firstWhere('account_code', '154');
        $dr1331 = $lines->firstWhere('account_code', '1331');
        $cr3311 = $lines->firstWhere('account_code', '3311');
        $this->assertEquals(2000000, $dr154->debit,  'Nợ 154 = tiền trước VAT');
        $this->assertEquals(200000,  $dr1331->debit, 'Nợ 1331 = VAT');
        $this->assertEquals(2200000, $cr3311->credit,'Có 3311 = tổng');

        // WIP chỉ = tiền trước VAT (không cộng VAT)
        $wip = ProjectWipEntry::find($expense->project_wip_entry_id);
        $this->assertEquals(2000000, $wip->amount, 'WIP = amount trước VAT, không cộng VAT');
    }

    /** TC3: Nợ 154 / Có 3341 → không cần NCC, employee_id được lưu */
    public function test_debit154_credit3341_employee_no_supplier_required(): void
    {
        AccountCode::firstOrCreate(['code' => '3341'], [
            'name' => 'Lương', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true, 'is_active' => true,
        ]);

        $this->store([
            'category'       => 'labor',
            'description'    => 'Trả lương nhân công',
            'amount'         => 5000000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '3341',
            'employee_id'    => $this->employee->id,
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $this->assertEquals($this->employee->id, $expense->employee_id);
        $this->assertNull($expense->supplier_id);

        $lines = JournalEntryLine::where('journal_entry_id', $expense->journal_entry_id)->get();
        $this->assertNotNull($lines->firstWhere('account_code', '154'));
        $this->assertNotNull($lines->firstWhere('account_code', '3341'));
    }

    /** TC4: TK Có = 3311 không có supplier_id → validate error */
    public function test_credit_3311_without_supplier_fails_validation(): void
    {
        $this->store([
            'category'       => 'equipment',
            'description'    => 'Thiếu NCC',
            'amount'         => 1000000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '3311',
            // supplier_id deliberately omitted
        ])->assertSessionHasErrors('supplier_id');
    }

    /** TC5: TK Có = 1111 không có fund_id → validate error */
    public function test_credit_1111_without_fund_fails_validation(): void
    {
        $this->store([
            'category'       => 'labor',
            'description'    => 'Thiếu quỹ',
            'amount'         => 500000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '1111',
            // fund_id deliberately omitted
        ])->assertSessionHasErrors('fund_id');
    }

    /** TC6: TK Nợ = 152 (vật tư) → bị chặn, không tạo JE */
    public function test_debit_152_is_blocked(): void
    {
        $this->store([
            'category'       => 'material',
            'description'    => 'Sai TK Nợ',
            'amount'         => 1000000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '152',
            'credit_account' => '1111',
            'fund_id'        => $this->fund->id,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('project_expenses', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    /** TC7: Cancel (remove) expense → JE bị đảo, WIP status → cancelled */
    public function test_remove_expense_reverses_je_and_cancels_wip(): void
    {
        $this->store([
            'category'       => 'labor',
            'description'    => 'CP để hủy',
            'amount'         => 3000000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '1111',
            'fund_id'        => $this->fund->id,
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $this->assertNotNull($expense->journal_entry_id);
        $this->assertNotNull($expense->project_wip_entry_id);
        $wipId = $expense->project_wip_entry_id;

        // Xóa expense
        $resp = $this->delete(route('projects.projects.expenses.destroy', [$this->project, $expense]))
            ->assertRedirect();
        if ($resp->getSession()->has('error')) {
            $this->fail('removeExpense trả về error: ' . $resp->getSession()->get('error'));
        }

        // Expense bị xóa
        $this->assertDatabaseMissing('project_expenses', ['id' => $expense->id]);

        // Draft JE bị xóa (isAuto=true → draft), không tạo reversal
        // Hoặc nếu đã posted → có reversal. Kiểm tra tổng hợp: không còn JE active
        $remainingActiveJes = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)
            ->whereIn('status', ['draft', 'posted'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->count();
        $this->assertEquals(0, $remainingActiveJes, 'JE gốc phải bị xóa hoặc đảo');

        // WIP cancelled
        $wip = ProjectWipEntry::find($wipId);
        $this->assertNotNull($wip);
        $this->assertEquals('cancelled', $wip->status, 'WIP phải bị cancelled');
    }

    /** TC8: Trùng số hóa đơn trong project_expenses → flash warning_duplicate */
    public function test_duplicate_invoice_number_shows_warning(): void
    {
        // Tạo sẵn expense với cùng invoice_number + supplier trong dự án này
        ProjectExpense::create([
            'project_id'     => $this->project->id,
            'category'       => 'equipment',
            'description'    => 'Chi phí gốc',
            'amount'         => 1000000,
            'expense_date'   => '2026-06-01',
            'debit_account'  => '154',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
            'invoice_number' => 'INV-DUP-001',
            'status'         => 'posted',
            'created_by'     => $this->user->id,
        ]);

        // Thêm expense thứ 2 với cùng supplier + invoice_number
        $this->store([
            'category'       => 'equipment',
            'description'    => 'Trùng số HĐ',
            'amount'         => 500000,
            'expense_date'   => '2026-06-10',
            'debit_account'  => '154',
            'credit_account' => '3311',
            'supplier_id'    => $this->supplier->id,
            'invoice_number' => 'INV-DUP-001',
        ])->assertRedirect()->assertSessionHas('warning_duplicate');

        // Expense thứ 2 chưa được tạo (còn 1 cái cũ)
        $this->assertDatabaseCount('project_expenses', 1);
    }
}
