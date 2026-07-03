<?php

namespace Tests\Feature;

use App\Enums\PrepaidExpenseStatus;
use App\Models\AccountCode;
use App\Models\PrepaidExpense;
use App\Models\User;
use App\Services\PrepaidExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Số dư đầu kỳ CPTT dương → amortized/remaining đúng
 * TC2: Số dư đầu kỳ CPTT âm → remainingAmount() không bị clamp về 0, JE đầu kỳ đảo chiều đúng (Dr 4111/Cr 242)
 * TC3: amortize() với remaining âm → JE đảo chiều (Dr 242/Cr chi phí) thay vì ghi số âm
 * TC4: Tạm dừng → amortize() từ chối cho kỳ hiệu lực, remaining/amortized không đổi
 * TC5: Tiếp tục → amortize() lại hoạt động bình thường
 * TC6: Đối soát GL cộng đúng dấu cho TK 242 (không dùng abs)
 */
class PrepaidExpenseOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private PrepaidExpenseService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['accounting.view', 'accounting.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->syncRoles([$adminRole]);
        $this->actingAs($this->user);

        $this->svc = app(PrepaidExpenseService::class);

        foreach ([
            ['code' => '242',  'name' => 'Chi phí trả trước dài hạn', 'type' => 'asset',   'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '6422', 'name' => 'Chi phí quản lý',           'type' => 'expense', 'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '4111', 'name' => 'Vốn đầu tư CSH',            'type' => 'equity',  'normal_balance' => 'credit', 'is_detail' => true],
        ] as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a);
        }
    }

    private function storeOpeningBalance(array $overrides = []): PrepaidExpense
    {
        $period = now()->format('Y-m');
        $data = array_merge([
            'description'            => 'CPTT đầu kỳ',
            'account_code'           => '242',
            'expense_account'        => '6422',
            'total_amount'           => 1_000_000,
            'months'                 => 5,
            'periods_elapsed'        => 2,
            'remaining_amount'       => 600_000,
            'opening_balance_period' => $period,
        ], $overrides);

        $resp = $this->post(route('accounting.prepaid-expenses.opening-balance.store'), $data);
        $resp->assertSessionDoesntHaveErrors();

        return PrepaidExpense::where('is_opening_balance', true)->latest('id')->firstOrFail();
    }

    // ── TC1 ─────────────────────────────────────────────────────────────

    public function test_positive_opening_balance_sets_amortized_and_remaining(): void
    {
        $expense = $this->storeOpeningBalance();

        $this->assertEquals(400_000, (float) $expense->amortized_amount);
        $this->assertEquals(600_000, $expense->remainingAmount());
        $this->assertEquals(PrepaidExpenseStatus::Active, $expense->status);

        $je = $expense->openingJournalEntry;
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);
        $this->assertTrue((bool) $je->exclude_from_period_movement);

        $line242 = $je->lines->firstWhere('account_code', '242');
        $this->assertEquals(600_000, (float) $line242->debit);
        $line4111 = $je->lines->firstWhere('account_code', '4111');
        $this->assertEquals(600_000, (float) $line4111->credit);
    }

    // ── TC2 ─────────────────────────────────────────────────────────────

    public function test_negative_opening_balance_not_clamped_and_je_reversed(): void
    {
        $expense = $this->storeOpeningBalance([
            'total_amount'     => 1_000_000,
            'periods_elapsed'  => 4,
            'remaining_amount' => -50_000,
        ]);

        $this->assertEquals(1_050_000, (float) $expense->amortized_amount);
        $this->assertEquals(-50_000, $expense->remainingAmount());

        $je = $expense->openingJournalEntry;
        $line242 = $je->lines->firstWhere('account_code', '242');
        $this->assertEquals(50_000, (float) $line242->credit);
        $this->assertEquals(0, (float) $line242->debit);
        $line4111 = $je->lines->firstWhere('account_code', '4111');
        $this->assertEquals(50_000, (float) $line4111->debit);
    }

    // ── TC3 ─────────────────────────────────────────────────────────────

    public function test_amortize_negative_amount_posts_reversed_journal_line(): void
    {
        $expense = $this->storeOpeningBalance([
            'total_amount'     => 1_000_000,
            'months'           => 5,
            'periods_elapsed'  => 4, // chỉ còn 1 kỳ
            'remaining_amount' => -20_000,
        ]);

        $period = now()->format('Y-m');
        $allocation = $this->svc->amortize($expense, $period);

        $this->assertEquals(-20_000, (float) $allocation->amount);

        $je = $allocation->journalEntry;
        $this->assertNotNull($je);
        $line242 = $je->lines->firstWhere('account_code', '242');
        $this->assertEquals(20_000, (float) $line242->debit);
        $lineExpense = $je->lines->firstWhere('account_code', '6422');
        $this->assertEquals(20_000, (float) $lineExpense->credit);

        $expense->refresh();
        $this->assertEquals(0.0, $expense->remainingAmount());
        $this->assertEquals(PrepaidExpenseStatus::FullyAmortized, $expense->status);
    }

    // ── TC4 ─────────────────────────────────────────────────────────────

    public function test_pause_prevents_amortize_and_preserves_remaining(): void
    {
        $expense = $this->storeOpeningBalance();
        $remainingBefore = $expense->remainingAmount();

        $this->post(route('accounting.prepaid-expenses.pause', $expense->id), ['reason' => 'Tạm dừng test'])
            ->assertSessionDoesntHaveErrors();

        $expense->refresh();
        $this->assertEquals('paused', $expense->allocation_status);

        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->amortize($expense, now()->format('Y-m'));
        } finally {
            $expense->refresh();
            $this->assertEquals($remainingBefore, $expense->remainingAmount());
        }
    }

    // ── TC5 ─────────────────────────────────────────────────────────────

    public function test_resume_allows_amortize_again(): void
    {
        $expense = $this->storeOpeningBalance();
        $this->svc->pause($expense, 'Tạm dừng');
        $expense->refresh();

        $this->post(route('accounting.prepaid-expenses.resume', $expense->id))
            ->assertSessionDoesntHaveErrors();

        $expense->refresh();
        $this->assertEquals('active', $expense->allocation_status);

        $allocation = $this->svc->amortize($expense, now()->format('Y-m'));
        $this->assertNotNull($allocation);
    }

    // ── TC6 ─────────────────────────────────────────────────────────────

    public function test_gl_reconcile_sums_signed_for_242(): void
    {
        $this->storeOpeningBalance([
            'total_amount'     => 1_000_000,
            'periods_elapsed'  => 4,
            'remaining_amount' => -50_000,
        ]);

        $resp = $this->get(route('accounting.prepaid-expenses.reports.gl-reconcile'));
        $resp->assertOk();
        $byAccount = collect($resp->viewData('page')['props']['byAccount']);
        $row242 = $byAccount->firstWhere('account', '242');

        $this->assertEqualsWithDelta(-50_000, $row242['gl_balance'], 0.01);
        $this->assertEqualsWithDelta(-50_000, $row242['book_remaining'], 0.01);
        $this->assertEqualsWithDelta(0, $row242['diff'], 0.01);
    }
}
