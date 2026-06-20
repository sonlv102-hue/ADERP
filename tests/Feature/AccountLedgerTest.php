<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sổ chi tiết tài khoản — kiểm tra resolve TK cha → con.
 * Bug gốc: exact match 'where account_code = 131' không thấy lines trên '1311'/'131UT'.
 */
class AccountLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');

        // updateOrCreate để không xung đột với seeder/existing data
        $accounts = [
            ['code' => '131',   'name' => 'Phải thu KH',       'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => null,  'level' => 2, 'is_detail' => false, 'is_active' => true],
            ['code' => '1311',  'name' => 'Phải thu nội địa',  'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => '131', 'level' => 3, 'is_detail' => true,  'is_active' => true],
            ['code' => '131UT', 'name' => 'Ứng trước KH',      'type' => 'asset',   'normal_balance' => 'credit', 'parent_code' => '131', 'level' => 3, 'is_detail' => true,  'is_active' => true],
            ['code' => '5111',  'name' => 'Doanh thu bán hàng','type' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => null,  'level' => 3, 'is_detail' => true,  'is_active' => true],
        ];
        foreach ($accounts as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], $acc);
        }
    }

    private function makePostedJe(string $date, array $lines): JournalEntry
    {
        $je = JournalEntry::create([
            'code'        => JournalEntry::generateCode(),
            'entry_date'  => $date,
            'description' => 'Test JE',
            'status'      => 'posted',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
            'posted_at'   => now(),
        ]);
        foreach ($lines as $i => $l) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_code'     => $l['account'],
                'debit'            => $l['debit'],
                'credit'           => $l['credit'],
                'sort_order'       => $i,
            ]);
        }
        return $je;
    }

    /** Chọn TK cha '131' phải thấy lines trên cả '1311' lẫn '131UT'. */
    public function test_parent_account_includes_child_lines(): void
    {
        // JE1 — Dr 1311 5M / Cr 5111 5M
        $this->makePostedJe('2026-03-10', [
            ['account' => '1311', 'debit' => 5_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0,         'credit' => 5_000_000],
        ]);
        // JE2 — Cr 1311 1.75M / Dr 131UT 1.75M
        $this->makePostedJe('2026-04-01', [
            ['account' => '1311',  'debit' => 0,         'credit' => 1_750_000],
            ['account' => '131UT', 'debit' => 1_750_000, 'credit' => 0],
        ]);

        $this->get(route('reports.account_ledger', [
            'account'   => '131',
            'date_from' => '2026-01-01',
            'date_to'   => '2026-12-31',
        ]))->assertInertia(fn ($page) => $page
            ->component('Reports/AccountLedger/Index')
            ->has('rows', 3)                                     // 1311(JE1) + 1311(JE2) + 131UT(JE2)
            ->where('totalDebit',  5_000_000 + 1_750_000)       // Dr 1311 5M + Dr 131UT 1.75M
            ->where('totalCredit', 1_750_000)                    // Cr 1311 1.75M
        );
    }

    /** Chọn TK chi tiết '1311' chỉ thấy lines của đúng '1311', không thấy '131UT'. */
    public function test_detail_account_shows_only_own_lines(): void
    {
        $this->makePostedJe('2026-03-10', [
            ['account' => '1311', 'debit' => 5_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0,         'credit' => 5_000_000],
        ]);
        // JE chỉ có 131UT — không được lên sổ khi chọn 1311
        $this->makePostedJe('2026-05-01', [
            ['account' => '131UT', 'debit' => 2_000_000, 'credit' => 0],
            ['account' => '5111',  'debit' => 0,         'credit' => 2_000_000],
        ]);

        $this->get(route('reports.account_ledger', [
            'account'   => '1311',
            'date_from' => '2026-01-01',
            'date_to'   => '2026-12-31',
        ]))->assertInertia(fn ($page) => $page
            ->component('Reports/AccountLedger/Index')
            ->has('rows', 1)
            ->where('totalDebit', 5_000_000)
            ->where('totalCredit', 0)
        );
    }

    /** Opening balance cũng phải tổng hợp từ TK con. */
    public function test_opening_balance_aggregates_child_accounts(): void
    {
        // JE trước kỳ: Dr 1311 10M (JE1) + Cr 131UT 3M (JE2)
        // '131' normal_balance=debit → opening = (10M+0) - (0+3M) = 7M
        $this->makePostedJe('2025-12-20', [
            ['account' => '1311', 'debit' => 10_000_000, 'credit' => 0],
            ['account' => '5111', 'debit' => 0,          'credit' => 10_000_000],
        ]);
        $this->makePostedJe('2025-12-25', [
            ['account' => '131UT', 'debit' => 0,         'credit' => 3_000_000],
            ['account' => '5111',  'debit' => 3_000_000, 'credit' => 0],
        ]);

        $this->get(route('reports.account_ledger', [
            'account'   => '131',
            'date_from' => '2026-01-01',
            'date_to'   => '2026-12-31',
        ]))->assertInertia(fn ($page) => $page
            ->component('Reports/AccountLedger/Index')
            ->where('openingBalance', 10_000_000 - 3_000_000)  // 7M
            ->has('rows', 0)
        );
    }

    /** Draft JEs không được xuất hiện trong sổ. */
    public function test_draft_je_not_included(): void
    {
        $je = JournalEntry::create([
            'code'        => JournalEntry::generateCode(),
            'entry_date'  => '2026-06-01',
            'description' => 'Draft JE',
            'status'      => 'draft',
            'is_auto'     => true,
            'created_by'  => $this->user->id,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_code'     => '1311',
            'debit'            => 9_000_000,
            'credit'           => 0,
            'sort_order'       => 0,
        ]);

        $this->get(route('reports.account_ledger', [
            'account'   => '131',
            'date_from' => '2026-01-01',
            'date_to'   => '2026-12-31',
        ]))->assertInertia(fn ($page) => $page
            ->component('Reports/AccountLedger/Index')
            ->has('rows', 0)
            ->where('openingBalance', 0)
        );
    }
}
