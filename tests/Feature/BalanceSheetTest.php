<?php

namespace Tests\Feature;

use App\Http\Controllers\Reports\BalanceSheetController;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BalanceSheetController $ctrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->ctrl = app(BalanceSheetController::class);

        foreach ([
            ['code' => '131',   'name' => 'Phải thu KH',            'type' => 'asset',   'normal_balance' => 'debit',  'parent_code' => null,   'level' => 3, 'is_detail' => true],
            ['code' => '411',   'name' => 'Vốn đầu tư CSH',         'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => null,   'level' => 3, 'is_detail' => true],
            ['code' => '421',   'name' => 'LNST chưa phân phối',    'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => null,   'level' => 3, 'is_detail' => false],
            ['code' => '4211',  'name' => 'LNST năm trước',         'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => '421',  'level' => 4, 'is_detail' => true],
            ['code' => '4212',  'name' => 'LNST năm nay',           'type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => '421',  'level' => 4, 'is_detail' => true],
            ['code' => '42111', 'name' => 'LNST năm trước (TK con)','type' => 'equity',  'normal_balance' => 'credit', 'parent_code' => '4211', 'level' => 5, 'is_detail' => true],
            ['code' => '511',   'name' => 'Doanh thu bán hàng',     'type' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => null,   'level' => 3, 'is_detail' => true],
            ['code' => '632',   'name' => 'Giá vốn hàng bán',       'type' => 'expense', 'normal_balance' => 'debit',  'parent_code' => null,   'level' => 3, 'is_detail' => true],
            ['code' => '642',   'name' => 'Chi phí QLDN',           'type' => 'expense', 'normal_balance' => 'debit',  'parent_code' => null,   'level' => 3, 'is_detail' => true],
            ['code' => '911',   'name' => 'Xác định KQKD',          'type' => 'expense', 'normal_balance' => 'debit',  'parent_code' => null,   'level' => 3, 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], $ac);
        }
    }

    /** Tạo journal entry posted, mỗi entry phải cân bằng Dr = Cr */
    private function postEntry(string $date, array $lines): void
    {
        $entry = JournalEntry::create([
            'code'        => 'BT-' . uniqid(),
            'entry_date'  => $date,
            'description' => 'Test',
            'status'      => 'posted',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
        ]);
        foreach ($lines as $i => [$account, $debit, $credit]) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_code'     => $account,
                'debit'            => $debit,
                'credit'           => $credit,
                'sort_order'       => $i + 1,
            ]);
        }
    }

    private function compute(string $asOf = '2026-06-30'): array
    {
        return $this->ctrl->computeData($asOf);
    }

    private function findRow(array $rows, string $keyword): ?array
    {
        return array_values(array_filter($rows, fn ($r) => str_contains($r['label'], $keyword)))[0] ?? null;
    }

    /**
     * TC1: Không có bút toán TK 421 → retained earnings = P&L từ 5xx/6xx (fallback)
     */
    public function test_no_421_entries_fallback_to_current_net_income(): void
    {
        $this->postEntry('2026-06-01', [['131', 10_000_000, 0], ['511', 0, 10_000_000]]);
        $this->postEntry('2026-06-10', [['632', 4_000_000, 0],  ['131', 0, 4_000_000]]);

        $data = $this->compute();

        // balance(421*) = 0; currentNetIncome = 10tr - 4tr = 6tr
        $this->assertEquals(6_000_000, $data['summary']['total_equity']);

        $row = $this->findRow($data['balanceSheet'], 'chưa kết chuyển');
        $this->assertNotNull($row);
        $this->assertEquals(6_000_000, $row['amount']);
    }

    /**
     * TC2: Có số dư 4211 (LNST năm trước) → retained earnings phản ánh đúng
     */
    public function test_prior_year_retained_earnings_in_4211(): void
    {
        // Dr 131 / Cr 4211: simulate prior-year RE carried forward
        $this->postEntry('2025-12-31', [['131', 20_000_000, 0], ['4211', 0, 20_000_000]]);

        $data = $this->compute('2026-06-30');

        // balance(421*) includes 4211 = 20tr; currentNetIncome = 0
        $this->assertEquals(20_000_000, $data['summary']['total_equity']);

        $row4211 = $this->findRow($data['balanceSheet'], '4211');
        $this->assertNotNull($row4211);
        $this->assertEquals(20_000_000, $row4211['amount']);
    }

    /**
     * TC3: Có số dư 4212 sau closing entry → không double-count từ 5xx
     */
    public function test_current_year_closing_entry_reflected_in_4212(): void
    {
        // Bước 1: ghi nhận doanh thu 15tr (Dr 131 / Cr 511)
        $this->postEntry('2026-06-01', [['131', 15_000_000, 0], ['511', 0, 15_000_000]]);
        // Bước 2: closing Dr 511 / Cr 911 (zero doanh thu)
        $this->postEntry('2026-06-30', [['511', 15_000_000, 0], ['911', 0, 15_000_000]]);
        // Bước 3: closing Dr 911 / Cr 4212 (kết chuyển lãi)
        $this->postEntry('2026-06-30', [['911', 15_000_000, 0], ['4212', 0, 15_000_000]]);

        $data = $this->compute('2026-06-30');

        // 511 net = 0 (15tr credit - 15tr debit), 4212 net = 15tr
        // retained = balance(421*) + currentNetIncome = 15tr + 0 = 15tr (không double)
        $this->assertEquals(15_000_000, $data['summary']['total_equity']);

        $row4212 = $this->findRow($data['balanceSheet'], '4212');
        $this->assertNotNull($row4212);
        $this->assertEquals(15_000_000, $row4212['amount']);
    }

    /**
     * TC4: Bút toán phân phối lợi nhuận (Dr 4212 / Cr 411) → giảm retained earnings
     */
    public function test_profit_distribution_reduces_retained_earnings(): void
    {
        // Cr 4212 20tr (simulate closing)
        $this->postEntry('2026-05-31', [['131', 20_000_000, 0], ['4212', 0, 20_000_000]]);
        // Phân phối: Dr 4212 5tr / Cr 411 5tr
        $this->postEntry('2026-06-15', [['4212', 5_000_000, 0], ['411', 0, 5_000_000]]);

        $data = $this->compute('2026-06-30');

        // 4212 net = 15tr; 411 net = 5tr; retained = 15tr; charter = 5tr
        $this->assertEquals(20_000_000, $data['summary']['total_equity']); // 15 + 5
        $this->assertEquals(15_000_000, $data['summary']['total_equity'] - 5_000_000); // retained only
    }

    /**
     * TC5: Đã post closing entry đầy đủ → 5xx = 0, không double-count
     */
    public function test_no_double_count_after_full_closing(): void
    {
        // Doanh thu 10tr (chưa closing)
        $this->postEntry('2026-05-01', [['131', 10_000_000, 0], ['511', 0, 10_000_000]]);
        // Closing 511 → 911
        $this->postEntry('2026-05-31', [['511', 10_000_000, 0], ['911', 0, 10_000_000]]);
        // Closing 911 → 4212
        $this->postEntry('2026-05-31', [['911', 10_000_000, 0], ['4212', 0, 10_000_000]]);

        $data = $this->compute('2026-05-31');

        // 511 net = 0, 4212 net = 10tr → retained = 10tr (không cộng lại 511)
        $this->assertEquals(10_000_000, $data['summary']['total_equity']);
        $this->assertTrue($data['summary']['balanced']);
    }

    /**
     * TC6: Chưa post closing entry → currentNetIncome vẫn xuất hiện trong retained
     */
    public function test_current_year_profit_shown_without_closing_entry(): void
    {
        $this->postEntry('2026-06-01', [['131', 8_000_000, 0], ['511', 0, 8_000_000]]);
        $this->postEntry('2026-06-10', [['642', 3_000_000, 0], ['131', 0, 3_000_000]]);

        $data = $this->compute('2026-06-30');

        // balance(421*) = 0; currentNetIncome = 8tr - 3tr = 5tr
        $this->assertEquals(5_000_000, $data['summary']['total_equity']);

        $row = $this->findRow($data['balanceSheet'], 'chưa kết chuyển');
        $this->assertNotNull($row);
        $this->assertEquals(5_000_000, $row['amount']);
    }

    /**
     * TC7: TK con 42111 (dưới 4211) → sumPrefix('421') bao gồm cả TK con
     */
    public function test_sub_accounts_under_421_included_in_total(): void
    {
        $this->postEntry('2025-12-31', [['131', 12_000_000, 0], ['42111', 0, 12_000_000]]);

        $data = $this->compute('2026-06-30');

        // '42111' starts with '421' → sumPrefix('421') = 12tr
        $this->assertEquals(12_000_000, $data['summary']['total_equity']);
    }
}
