<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * PHA 0 — engine ghi sổ chi phí phát sinh dự án.
 *
 * TC1: addExpense tạo expense + JE Nợ154/Có6279 + WipEntry (category=other)
 * TC2: JE cân Nợ = Có
 * TC3: WipEntry.source_type trỏ về ProjectExpense, source_id đúng
 * TC4: Nếu createFromExpense fail → expense không được insert (rollback)
 * TC5: amount = 0 → không tạo JE, không tạo WipEntry (hợp lệ)
 * TC6: category=labor → credit TK 6271
 * TC7: category=transport → credit TK 6278
 */
class ProjectExpenseJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;

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

        $customer = \App\Models\Customer::create(['code' => 'KH-T01', 'name' => 'KH Test', 'phone' => '0900000001']);

        $this->project = Project::create([
            'code'        => 'DA-TEST',
            'name'        => 'Dự án test',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
        ]);

        // TK cần thiết cho bút toán expense (bao gồm Cr TK phải trả và tiền)
        foreach ([
            ['code' => '154',  'name' => 'CP SXKD dở dang',     'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '3311', 'name' => 'NCC trong nước',        'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '1111', 'name' => 'Tiền VNĐ',              'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1121', 'name' => 'TG NH VNĐ',             'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT KT',          'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6271', 'name' => 'Lương giám sát',         'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6272', 'name' => 'Vật tư phụ',             'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6278', 'name' => 'CP vận chuyển',          'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6279', 'name' => 'CP khác',                'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['is_active' => true]));
        }
    }

    /** TC1: addExpense (category=other) → JE + WipEntry được tạo */
    public function test_add_expense_creates_journal_entry_and_wip_entry(): void
    {
        $response = $this->post(route('projects.projects.expenses.store', $this->project), [
            'category'     => 'other',
            'description'  => 'Chi phí thuê xe nâng',
            'amount'       => 5000000,
            'expense_date' => '2026-06-10',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $this->assertNotNull($expense, 'ProjectExpense phải được tạo');
        $this->assertEquals(5000000, (float) $expense->amount);

        // TC2: JE tồn tại và cân Nợ = Có
        $je = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)
            ->first();
        $this->assertNotNull($je, 'JournalEntry phải được tạo');

        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();
        $this->assertCount(2, $lines, 'Phải có đúng 2 dòng JE');
        $totalDebit  = $lines->sum('debit');
        $totalCredit = $lines->sum('credit');
        $this->assertEquals($totalDebit, $totalCredit, 'Nợ phải bằng Có');
        $this->assertEquals(5000000, $totalDebit);

        // TT133: Nợ 6279 (chi phí other) / Có 3311 (phải trả NCC — payable default)
        $this->assertNotNull($lines->firstWhere('account_code', '6279'), 'Phải có dòng Nợ TK 6279');
        $this->assertNotNull($lines->firstWhere('account_code', '3311'), 'Phải có dòng Có TK 3311');
        $this->assertEquals(5000000, $lines->firstWhere('account_code', '6279')->debit);
        $this->assertEquals(5000000, $lines->firstWhere('account_code', '3311')->credit);

        // TC3: WipEntry source trỏ đúng
        $wip = ProjectWipEntry::where('source_type', ProjectExpense::class)
            ->where('source_id', $expense->id)
            ->first();
        $this->assertNotNull($wip, 'ProjectWipEntry phải được tạo');
        $this->assertEquals($expense->id, $wip->source_id);
        $this->assertEquals($je->id, $wip->journal_entry_id);
        $this->assertEquals('overhead', $wip->cost_type);
    }

    /** TC4: Nếu validateLines fail → rollback toàn bộ transaction */
    public function test_journal_failure_rolls_back_expense(): void
    {
        // Set TK 6279 (debit TK cho category=other) là_detail=false → validateLines throw InvalidArgumentException
        AccountCode::where('code', '6279')->update(['is_detail' => false]);

        $response = $this->post(route('projects.projects.expenses.store', $this->project), [
            'category'     => 'other',
            'description'  => 'Chi phí sẽ rollback',
            'amount'       => 1000000,
            'expense_date' => '2026-06-10',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error'); // phải surface lỗi cho user

        $this->assertDatabaseMissing('project_expenses', ['description' => 'Chi phí sẽ rollback']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('project_wip_entries', 0);
    }

    /** TC5: amount = 0 → không tạo JE, không tạo WipEntry */
    public function test_zero_amount_skips_journal(): void
    {
        $this->post(route('projects.projects.expenses.store', $this->project), [
            'category'     => 'other',
            'description'  => 'Ghi nhận miễn phí',
            'amount'       => 0,
            'expense_date' => '2026-06-10',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertDatabaseCount('project_expenses', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('project_wip_entries', 0);
    }

    /** TC6: category=labor → credit TK 6271 */
    public function test_labor_category_credits_tk6271(): void
    {
        $this->post(route('projects.projects.expenses.store', $this->project), [
            'category'     => 'labor',
            'description'  => 'Chi phí nhân công',
            'amount'       => 3000000,
            'expense_date' => '2026-06-10',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        // TT133: Nợ 6271 (nhân công) / Có 3311 (phải trả)
        $this->assertNotNull($lines->firstWhere('account_code', '6271'), 'Nhân công → Dr TK 6271');
        $this->assertEquals(3000000, $lines->firstWhere('account_code', '6271')->debit);
        $this->assertEquals(3000000, $lines->firstWhere('account_code', '3311')->credit);
    }

    /** TC7: category=transport → credit TK 6278 */
    public function test_transport_category_credits_tk6278(): void
    {
        $this->post(route('projects.projects.expenses.store', $this->project), [
            'category'     => 'transport',
            'description'  => 'Chi phí vận chuyển',
            'amount'       => 2000000,
            'expense_date' => '2026-06-10',
        ])->assertRedirect()->assertSessionMissing('error');

        $expense = ProjectExpense::first();
        $je = JournalEntry::where('reference_type', ProjectExpense::class)->where('reference_id', $expense->id)->first();
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();

        // TT133: Nợ 6278 (vận chuyển) / Có 3311 (phải trả)
        $this->assertNotNull($lines->firstWhere('account_code', '6278'), 'Vận chuyển → Dr TK 6278');
        $this->assertEquals(2000000, $lines->firstWhere('account_code', '6278')->debit);
        $this->assertEquals(2000000, $lines->firstWhere('account_code', '3311')->credit);
    }
}
