<?php

namespace Tests\Feature\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\InternalBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class InternalTransferReportTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;
    private InternalBankAccount $internalAcc;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($user, $ability) => true);

        $this->bank = BankAccount::create([
            'name'            => 'Test Bank Account',
            'bank_name'       => 'TestBank',
            'account_number'  => '123',
            'account_name'    => 'Test',
            'branch'          => '',
            'opening_balance' => 0,
        ]);

        $this->internalAcc = InternalBankAccount::create([
            'name'           => 'Acc A',
            'account_number' => 'ACC-A',
            'bank_name'      => 'TestBank',
            'is_active'      => true,
        ]);
    }

    private function makeTx(string $date, float $debit = 0, float $credit = 0, ?int $internalId = null, string $status = 'pending'): BankTransaction
    {
        return BankTransaction::create([
            'bank_account_id'    => $this->bank->id,
            'transaction_date'   => $date,
            'description'        => 'Test transfer',
            'debit'              => $debit,
            'credit'             => $credit,
            'tx_type'            => 'internal_transfer',
            'internal_account_id' => $internalId ?? $this->internalAcc->id,
            'internal_status'    => $status,
        ]);
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /** @test */
    public function filter_by_month_returns_only_that_month(): void
    {
        $this->makeTx('2026-06-10', debit: 1000000);
        $this->makeTx('2026-07-01', debit: 500000);

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'month',
            'month'       => '2026-06',
        ]))->assertInertia(fn ($page) => $page
            ->has('transactions', 1)
            ->where('periodLabel', 'Tháng 6/2026')
            ->where('periodType', 'month')
        );
    }

    /** @test */
    public function filter_by_year_returns_all_months_in_year(): void
    {
        $this->makeTx('2026-01-15', debit: 100000);
        $this->makeTx('2026-06-10', credit: 200000);
        $this->makeTx('2025-12-31', debit: 300000); // outside

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'year',
            'year'        => '2026',
        ]))->assertInertia(fn ($page) => $page
            ->has('transactions', 2)
            ->where('periodLabel', 'Năm 2026')
            ->where('periodType', 'year')
        );
    }

    /** @test */
    public function filter_by_custom_date_range(): void
    {
        $this->makeTx('2026-06-01', debit: 100000);
        $this->makeTx('2026-06-10', debit: 200000);
        $this->makeTx('2026-06-20', credit: 150000);
        $this->makeTx('2026-06-25', debit: 300000); // outside range

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'custom',
            'from_date'   => '2026-06-01',
            'to_date'     => '2026-06-20',
        ]))->assertInertia(fn ($page) => $page
            ->has('transactions', 3)
            ->where('periodLabel', 'Từ 01/06/2026 đến 20/06/2026')
        );
    }

    /** @test */
    public function filter_all_returns_every_transaction(): void
    {
        $this->makeTx('2025-01-01', debit: 100000);
        $this->makeTx('2026-06-10', credit: 200000);
        $this->makeTx('2026-12-31', debit: 300000);

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'all',
        ]))->assertInertia(fn ($page) => $page
            ->has('transactions', 3)
            ->where('periodLabel', 'Tất cả')
            ->where('periodType', 'all')
        );
    }

    /** @test */
    public function filter_combined_account_and_year(): void
    {
        $otherAcc = InternalBankAccount::create([
            'name' => 'Acc B', 'account_number' => 'ACC-B',
            'bank_name' => 'TestBank', 'is_active' => true,
        ]);

        $this->makeTx('2026-03-01', debit: 100000, internalId: $this->internalAcc->id);
        $this->makeTx('2026-05-01', debit: 200000, internalId: $otherAcc->id);

        $this->get(route('accounting.internal-transfers.index', [
            'period_type'          => 'year',
            'year'                 => '2026',
            'internal_account_ids' => [$this->internalAcc->id],
        ]))->assertInertia(fn ($page) => $page->has('transactions', 1));
    }

    /** @test */
    public function custom_range_from_date_after_to_date_returns_validation_error(): void
    {
        $response = $this->getJson(route('accounting.internal-transfers.index', [
            'period_type' => 'custom',
            'from_date'   => '2026-06-20',
            'to_date'     => '2026-06-01',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to_date']);
    }

    /** @test */
    public function summary_cards_match_filtered_transactions(): void
    {
        $this->makeTx('2026-06-05', debit: 500000);
        $this->makeTx('2026-06-10', credit: 300000);
        $this->makeTx('2026-06-15', debit: 200000, status: 'docs_done');
        $this->makeTx('2026-07-01', debit: 999000); // outside

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'month',
            'month'       => '2026-06',
        ]))->assertInertia(fn ($page) => $page
            ->where('summary.total_debit', 700000)
            ->where('summary.total_credit', 300000)
            ->where('summary.net', -400000)
            ->where('summary.count', 3)
        );
    }

    /** @test */
    public function transactions_outside_period_do_not_appear(): void
    {
        $this->makeTx('2025-12-31', debit: 111000);
        $this->makeTx('2026-06-01', debit: 222000);
        $this->makeTx('2027-01-01', credit: 333000);

        $this->get(route('accounting.internal-transfers.index', [
            'period_type' => 'custom',
            'from_date'   => '2026-01-01',
            'to_date'     => '2026-12-31',
        ]))->assertInertia(fn ($page) => $page
            ->has('transactions', 1)
            ->where('summary.total_debit', 222000)
        );
    }
}
