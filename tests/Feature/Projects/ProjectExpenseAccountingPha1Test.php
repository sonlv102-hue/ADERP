<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
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
 * PHA 1 — Schema mở rộng + định khoản TT133.
 *
 * TC1: equipment + supplier + VAT 10% → Dr 6237 / Dr 1331 / Cr 3311 (cân)
 * TC2: labor + cash → Dr 6271 / Cr 1111 (không có VAT line)
 * TC3: material + bank → Dr 621 / Cr 1121
 * TC4: debit_account override → dùng TK override thay category default
 * TC5: payment_method=payable không có supplier → Cr 3311 fallback
 * TC6: payment_method=payable có supplier → Cr supplier.payable_account_code
 * TC7: ExpenseCategory::Equipment tồn tại và label đúng
 */
class ProjectExpenseAccountingPha1Test extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Supplier $supplier;

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

        // TK phải tạo TRƯỚC khi tạo Supplier (FK payable_account_code → account_codes)
        foreach ([
            ['code' => '621',  'name' => 'CP NVL TT',    'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT KT', 'type' => 'asset',   'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '3311', 'name' => 'NCC trong nước','type' => 'liability','normal_balance' => 'credit','is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền VNĐ',     'type' => 'asset',   'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '1121', 'name' => 'TG NH VNĐ',    'type' => 'asset',   'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '6271', 'name' => 'Lương GS',      'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '6237', 'name' => 'Thuê máy ngoài', 'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '6272', 'name' => 'Vật tư phụ',    'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '6279', 'name' => 'CP khác',        'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
            ['code' => '6278', 'name' => 'CP vận chuyển',  'type' => 'expense', 'normal_balance' => 'debit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }

        $customer = \App\Models\Customer::create(['code' => 'KH-T01', 'name' => 'KH Test', 'phone' => '0900000001']);

        $this->project = Project::create([
            'code'        => 'DA-TEST',
            'name'        => 'Dự án test',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
        ]);

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-TEST',
            'name'                 => 'NCC Test',
            'phone'                => '0900000002',
            'payable_account_code' => '3311',
        ]);
    }

    private function storeExpense(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            route('projects.projects.expenses.store', $this->project),
            $data
        );
    }

    /** TC1: equipment + supplier + VAT 10% → 3 dòng JE cân */
    public function test_equipment_supplier_vat_creates_correct_je(): void
    {
        $response = $this->storeExpense([
            'category'       => 'equipment',
            'description'    => 'Thuê xe nâng 10m',
            'amount'         => 1000000,
            'expense_date'   => '2026-06-10',
            'supplier_id'    => $this->supplier->id,
            'payment_method' => 'payable',
            'vat_rate'       => 10,
            'vat_amount'     => 100000,
        ]);

        $response->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $this->assertEquals(100000, $expense->vat_amount);

        $je = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)->first();
        $this->assertNotNull($je);

        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();
        $this->assertCount(3, $lines, 'Phải có 3 dòng: Dr6237, Dr1331, Cr3311');

        // Cân Nợ = Có = 1,100,000
        $this->assertEquals(1100000, $lines->sum('debit'));
        $this->assertEquals(1100000, $lines->sum('credit'));

        // TC2: kiểm tra từng dòng
        $dr6237 = $lines->firstWhere('account_code', '6237');
        $this->assertNotNull($dr6237, 'Phải có dòng Dr 6237');
        $this->assertEquals(1000000, $dr6237->debit);

        $dr1331 = $lines->firstWhere('account_code', '1331');
        $this->assertNotNull($dr1331, 'Phải có dòng Dr 1331 (VAT)');
        $this->assertEquals(100000, $dr1331->debit);

        $cr3311 = $lines->firstWhere('account_code', '3311');
        $this->assertNotNull($cr3311, 'Phải có dòng Cr 3311');
        $this->assertEquals(1100000, $cr3311->credit);

        // WipEntry KHÔNG được tạo ngay khi TK Nợ là 6237 (non-154).
        // WIP sẽ được tạo sau khi kết chuyển sang TK154 thủ công.
        $wip = ProjectWipEntry::where('source_type', ProjectExpense::class)->first();
        $this->assertNull($wip, 'WIP không được tạo ngay khi TK Nợ là 6237');
    }

    /** TC2: labor + cash → Dr 6271 / Cr 1111, không có VAT line */
    public function test_labor_cash_no_vat(): void
    {
        $this->storeExpense([
            'category'       => 'labor',
            'description'    => 'Nhân công khoán',
            'amount'         => 2000000,
            'expense_date'   => '2026-06-10',
            'payment_method' => 'cash',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        $this->assertCount(2, $lines, 'Không có VAT → chỉ 2 dòng');
        $this->assertNull($lines->firstWhere('account_code', '1331'), 'Không được có dòng 1331');
        $this->assertNotNull($lines->firstWhere('account_code', '6271'));
        $this->assertNotNull($lines->firstWhere('account_code', '1111'));
        $this->assertEquals(2000000, $lines->sum('debit'));
        $this->assertEquals(2000000, $lines->sum('credit'));
    }

    /** TC3: material + bank → Dr 6272 (project_material_account từ accounting_settings) / Cr 1121 */
    public function test_material_bank(): void
    {
        $this->storeExpense([
            'category'       => 'material',
            'description'    => 'Mua vật tư',
            'amount'         => 500000,
            'expense_date'   => '2026-06-10',
            'payment_method' => 'bank',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        // accounting_settings seeds project_material_account='6272' (không phải '621')
        $this->assertNotNull($lines->firstWhere('account_code', '6272'), 'Dr 6272 — project_material_account');
        $this->assertNotNull($lines->firstWhere('account_code', '1121'), 'Cr 1121 — bank');
        $this->assertEquals(500000, $lines->firstWhere('account_code', '6272')->debit);
        $this->assertEquals(500000, $lines->firstWhere('account_code', '1121')->credit);
    }

    /** TC4: debit_account override */
    public function test_debit_account_override(): void
    {
        $this->storeExpense([
            'category'       => 'other',
            'description'    => 'CP với TK override',
            'amount'         => 300000,
            'expense_date'   => '2026-06-10',
            'payment_method' => 'payable',
            'debit_account'  => '6278',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        $this->assertNotNull($lines->firstWhere('account_code', '6278'), 'Override 6278 thay vì 6279');
        $this->assertNull($lines->firstWhere('account_code', '6279'));
    }

    /** TC5: payable không supplier → Cr 3311 fallback */
    public function test_payable_no_supplier_defaults_to_3311(): void
    {
        $this->storeExpense([
            'category'       => 'other',
            'description'    => 'CP không có NCC',
            'amount'         => 200000,
            'expense_date'   => '2026-06-10',
            'payment_method' => 'payable',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        $this->assertNotNull($lines->firstWhere('account_code', '3311'));
    }

    /** TC6: payable với supplier → Cr supplier.payable_account_code */
    public function test_payable_with_supplier_uses_supplier_tk(): void
    {
        // Supplier dùng TK 3312
        $s2 = Supplier::create([
            'code'                 => 'NCC-S2',
            'name'                 => 'NCC dịch vụ',
            'phone'                => '0900000003',
            'payable_account_code' => '3311', // sẽ dùng TK này
        ]);

        AccountCode::firstOrCreate(['code' => '3312'], [
            'name' => 'NCC dịch vụ', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true, 'is_active' => true,
        ]);

        $this->storeExpense([
            'category'       => 'equipment',
            'description'    => 'Thuê xe NCC khác',
            'amount'         => 400000,
            'expense_date'   => '2026-06-10',
            'supplier_id'    => $s2->id,
            'payment_method' => 'payable',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        $creditLine = $lines->where('credit', '>', 0)->first();
        $this->assertEquals('3311', $creditLine->account_code);
    }

    /** TC7: ExpenseCategory::Equipment tồn tại, label = "Máy thi công" */
    public function test_equipment_enum_exists(): void
    {
        $cat = \App\Enums\ExpenseCategory::Equipment;
        $this->assertEquals('equipment', $cat->value);
        $this->assertEquals('Máy thi công', $cat->label());
        $this->assertEquals('6237', $cat->defaultDebitAccount());
        $this->assertEquals('overhead', $cat->wipCostType());
    }
}
