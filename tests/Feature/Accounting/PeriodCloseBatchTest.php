<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PeriodCloseBatch;
use App\Models\User;
use App\Services\Accounting\PeriodCloseService;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PeriodCloseBatchTest extends TestCase
{
    use RefreshDatabase;

    private PeriodCloseService $service;
    private AccountingService  $accounting;
    private User               $user;
    private AccountingPeriod   $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        $this->accounting = app(AccountingService::class);
        $this->service    = app(PeriodCloseService::class);

        $this->period = AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $accounts = [
            ['511',  'Doanh thu bán hàng',           'revenue', 'credit', null,  1, false],
            ['5111', 'Doanh thu bán hàng hóa',        'revenue', 'credit', '511', 2, true],
            ['5113', 'Doanh thu cung cấp dịch vụ',    'revenue', 'credit', '511', 2, true],
            ['515',  'Doanh thu tài chính',            'revenue', 'credit', null,  1, true],
            ['711',  'Thu nhập khác',                  'revenue', 'credit', null,  1, true],
            ['6',    'Chi phí',                        'expense', 'debit',  null,  1, false],
            ['632',  'Giá vốn hàng bán',              'expense', 'debit',  '6',   2, true],
            ['6421', 'Chi phí bán hàng',               'expense', 'debit',  '6',   2, true],
            ['6422', 'Chi phí QLDN',                   'expense', 'debit',  '6',   2, true],
            ['811',  'Chi phí khác',                   'expense', 'debit',  null,  1, true],
            ['821',  'Chi phí thuế TNDN',              'expense', 'debit',  null,  1, true],
            ['154',  'Chi phí SXKD dở dang',          'asset',   'debit',  null,  1, true],
            ['156',  'Hàng hóa',                       'asset',   'debit',  null,  1, true],
            ['9',    'TK 9xx',                          'equity',  'credit', null,  1, false],
            ['911',  'Xác định kết quả KD',            'equity',  'credit', '9',   2, true],
            ['42',   'Lợi nhuận',                      'equity',  'credit', null,  1, false],
            ['421',  'LNST chưa phân phối',            'equity',  'credit', '42',  2, false],
            ['4212', 'LNST năm nay',                   'equity',  'credit', '421', 3, true],
            ['111',  'Tiền mặt',                       'asset',   'debit',  null,  1, false],
            ['1111', 'Tiền mặt tại quỹ',               'asset',   'debit',  '111', 2, true],
        ];
        foreach ($accounts as [$code, $name, $type, $nb, $parent, $lvl, $detail]) {
            AccountCode::updateOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'normal_balance' => $nb,
                'parent_code' => $parent, 'level' => $lvl, 'is_detail' => $detail, 'is_active' => true,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function postJE(array $lines): JournalEntry
    {
        return $this->accounting->post(
            'Test JE', now()->startOfMonth(),
            $lines, null, null, false, null, null, false, '2026-06'
        );
    }

    private function makeRevenueCogs(int $revenue = 20_000_000, int $cogs = 8_000_000): void
    {
        $this->postJE([
            ['account' => '1111', 'debit' => $revenue, 'credit' => 0,        'description' => 'Bán hàng'],
            ['account' => '5111', 'debit' => 0,        'credit' => $revenue, 'description' => 'DT bán hàng'],
        ]);
        $this->postJE([
            ['account' => '632',  'debit' => $cogs,    'credit' => 0,        'description' => 'Giá vốn'],
            ['account' => '1111', 'debit' => 0,        'credit' => $cogs,    'description' => 'Giá vốn'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group: preview (dry-run)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_preview_does_not_create_journal_entries(): void
    {
        $this->makeRevenueCogs();
        $jeBefore = JournalEntry::count();

        $this->service->preview('2026-06');

        $this->assertEquals($jeBefore, JournalEntry::count());
        $this->assertDatabaseCount('period_close_batches', 0);
    }

    public function test_preview_returns_correct_totals(): void
    {
        $this->makeRevenueCogs(20_000_000, 8_000_000);

        $result = $this->service->preview('2026-06');

        $this->assertEquals(20_000_000, $result['totalRevenue']);
        $this->assertEquals(8_000_000,  $result['totalExpense']);
        $this->assertEquals(12_000_000, $result['profitOrLoss']);
        $this->assertNotEmpty($result['revenueLines']);
        $this->assertNotEmpty($result['expenseLines']);
        $this->assertNotEmpty($result['profitLines']);
    }

    public function test_preview_warns_critical_if_batch_already_active(): void
    {
        $this->makeRevenueCogs();
        $this->service->closeWithBatch('2026-06', $this->user->id);

        $result = $this->service->preview('2026-06');

        $this->assertTrue($result['hasCritical']);
        $criticals = array_filter($result['warnings'], fn ($w) => $w['type'] === 'critical');
        $codes = array_column(array_values($criticals), 'code');
        $this->assertContains('batch_exists', $codes);
    }

    public function test_preview_warns_critical_if_period_locked(): void
    {
        $this->period->update(['status' => 'locked']);

        $result = $this->service->preview('2026-06');

        $this->assertTrue($result['hasCritical']);
        $criticals = array_filter($result['warnings'], fn ($w) => $w['type'] === 'critical');
        $codes = array_column(array_values($criticals), 'code');
        $this->assertContains('period_locked', $codes);
    }

    public function test_preview_warns_info_for_wip_balance(): void
    {
        $this->postJE([
            ['account' => '154',  'debit' => 5_000_000, 'credit' => 0,         'description' => 'WIP'],
            ['account' => '1111', 'debit' => 0,          'credit' => 5_000_000, 'description' => 'WIP'],
        ]);

        $result = $this->service->preview('2026-06');

        $infos = array_filter($result['warnings'], fn ($w) => $w['type'] === 'info');
        $codes = array_column(array_values($infos), 'code');
        $this->assertContains('wip_balance', $codes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group: closeWithBatch
    // ─────────────────────────────────────────────────────────────────────────

    public function test_close_with_batch_creates_batch_and_journal_entries(): void
    {
        $this->makeRevenueCogs();

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertInstanceOf(PeriodCloseBatch::class, $batch);
        $this->assertEquals('posted', $batch->status);
        $this->assertNotNull($batch->posted_at);
        $this->assertGreaterThan(0, $batch->journal_entry_count);

        // JEs có period_close_batch_id
        $jeCount = JournalEntry::where('period_close_batch_id', $batch->id)->count();
        $this->assertEquals($batch->journal_entry_count, $jeCount);
    }

    public function test_close_with_batch_sets_source_type_period_close(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $jes = JournalEntry::where('period_close_batch_id', $batch->id)->get();
        foreach ($jes as $je) {
            $this->assertEquals('period_close', $je->source_type);
            $this->assertEquals('posted', $je->status);
        }
    }

    public function test_close_with_batch_ketchuyen_revenue_accounts(): void
    {
        $revenue = 25_000_000;
        $this->postJE([
            ['account' => '1111', 'debit' => $revenue, 'credit' => 0,       'description' => 'DT'],
            ['account' => '5111', 'debit' => 0,        'credit' => $revenue, 'description' => 'DT'],
        ]);

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertEquals($revenue, $batch->total_revenue);

        // Kiểm tra có dòng Dr 5111 / Cr 911
        $revenueJE = JournalEntry::where('period_close_batch_id', $batch->id)
            ->where('description', 'like', '%doanh thu%')
            ->first();
        $this->assertNotNull($revenueJE);
        $line5111 = $revenueJE->lines->where('account_code', '5111')->where('debit', $revenue)->first();
        $this->assertNotNull($line5111);
    }

    public function test_close_with_batch_ketchuyen_expense_accounts(): void
    {
        $this->makeRevenueCogs();
        $adminExp = 3_000_000;
        $this->postJE([
            ['account' => '6422', 'debit' => $adminExp, 'credit' => 0,         'description' => 'CP'],
            ['account' => '1111', 'debit' => 0,          'credit' => $adminExp, 'description' => 'CP'],
        ]);

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertEquals(8_000_000 + $adminExp, $batch->total_expense);
    }

    public function test_close_with_batch_does_not_ketchuyen_154_156(): void
    {
        $this->makeRevenueCogs();
        $this->postJE([
            ['account' => '154',  'debit' => 5_000_000, 'credit' => 0,         'description' => 'WIP'],
            ['account' => '1111', 'debit' => 0,          'credit' => 5_000_000, 'description' => 'WIP'],
        ]);
        $this->postJE([
            ['account' => '156',  'debit' => 2_000_000, 'credit' => 0,         'description' => 'Tồn kho'],
            ['account' => '1111', 'debit' => 0,          'credit' => 2_000_000, 'description' => 'Tồn kho'],
        ]);

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        // 154 và 156 không góp vào total_expense
        $this->assertEquals(8_000_000, $batch->total_expense);

        // Không có dòng bút toán nào chứa 154 hoặc 156 trong batch
        $jeIds = JournalEntry::where('period_close_batch_id', $batch->id)->pluck('id');
        $has154 = \App\Models\JournalEntryLine::whereIn('journal_entry_id', $jeIds)
            ->whereIn('account_code', ['154', '156'])->exists();
        $this->assertFalse($has154);
    }

    public function test_close_with_batch_ketchuyen_821_only_when_has_balance(): void
    {
        $this->makeRevenueCogs();

        // Không có phát sinh 821 → batch không kết chuyển 821
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $jeIds = JournalEntry::where('period_close_batch_id', $batch->id)->pluck('id');
        $has821 = \App\Models\JournalEntryLine::whereIn('journal_entry_id', $jeIds)
            ->where('account_code', '821')->exists();
        $this->assertFalse($has821);
    }

    public function test_close_with_batch_ketchuyen_821_when_has_balance(): void
    {
        $this->makeRevenueCogs();
        $this->postJE([
            ['account' => '821',  'debit' => 1_000_000, 'credit' => 0,         'description' => 'TNDN'],
            ['account' => '1111', 'debit' => 0,          'credit' => 1_000_000, 'description' => 'TNDN'],
        ]);

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $jeIds = JournalEntry::where('period_close_batch_id', $batch->id)->pluck('id');
        $has821 = \App\Models\JournalEntryLine::whereIn('journal_entry_id', $jeIds)
            ->where('account_code', '821')->exists();
        $this->assertTrue($has821);
    }

    public function test_close_with_batch_profit_scenario(): void
    {
        $this->makeRevenueCogs(20_000_000, 8_000_000); // lãi 12M

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertEquals(12_000_000, $batch->profit_or_loss);

        // JE lợi nhuận: Dr 911 / Cr 4212
        $profitJE = JournalEntry::where('period_close_batch_id', $batch->id)
            ->where('description', 'like', '%lợi nhuận%')
            ->first();
        $this->assertNotNull($profitJE);
        $dr911  = $profitJE->lines->where('account_code', '911')->where('debit', 12_000_000)->first();
        $cr4212 = $profitJE->lines->where('account_code', '4212')->where('credit', 12_000_000)->first();
        $this->assertNotNull($dr911);
        $this->assertNotNull($cr4212);
    }

    public function test_close_with_batch_loss_scenario(): void
    {
        $this->makeRevenueCogs(5_000_000, 12_000_000); // lỗ 7M

        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->assertEquals(-7_000_000, $batch->profit_or_loss);

        // JE lỗ: Dr 4212 / Cr 911
        $lossJE = JournalEntry::where('period_close_batch_id', $batch->id)
            ->where('description', 'like', '%lỗ%')
            ->first();
        $this->assertNotNull($lossJE);
        $dr4212 = $lossJE->lines->where('account_code', '4212')->where('debit', 7_000_000)->first();
        $cr911  = $lossJE->lines->where('account_code', '911')->where('credit', 7_000_000)->first();
        $this->assertNotNull($dr4212);
        $this->assertNotNull($cr911);
    }

    public function test_close_with_batch_throws_if_already_active_batch(): void
    {
        $this->makeRevenueCogs();
        $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->service->closeWithBatch('2026-06', $this->user->id);
    }

    public function test_close_with_batch_trial_balance_still_balanced(): void
    {
        $this->makeRevenueCogs(20_000_000, 8_000_000);
        $this->service->closeWithBatch('2026-06', $this->user->id);

        $totals = \Illuminate\Support\Facades\DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->selectRaw('SUM(jl.debit) as total_debit, SUM(jl.credit) as total_credit')
            ->first();

        $this->assertEquals((int)$totals->total_debit, (int)$totals->total_credit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group: reverseBatch
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reverse_batch_changes_status_to_reversed(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);

        $this->service->reverseBatch($batch, $this->user->id, 'Sai số liệu');

        $batch->refresh();
        $this->assertEquals('reversed', $batch->status);
        $this->assertNotNull($batch->reversed_at);
        $this->assertEquals('Sai số liệu', $batch->reverse_reason);
    }

    public function test_reverse_batch_creates_reversal_journal_entries(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $jeBefore = JournalEntry::count();

        $this->service->reverseBatch($batch, $this->user->id, 'Điều chỉnh');

        // Số JE tăng thêm bằng số JE gốc của batch
        $jeAfter = JournalEntry::count();
        $this->assertEquals($jeBefore + $batch->journal_entry_count, $jeAfter);

        // Original JEs đổi sang reversed
        $originalJes = JournalEntry::where('period_close_batch_id', $batch->id)
            ->where('description', 'not like', 'Đảo:%')
            ->get();
        foreach ($originalJes as $je) {
            $this->assertEquals('reversed', $je->fresh()->status);
        }
    }

    public function test_after_reverse_can_close_again(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $this->service->reverseBatch($batch, $this->user->id, 'Re-run');

        // Sau khi đảo, có thể closeWithBatch lại
        $newBatch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $this->assertEquals('posted', $newBatch->status);
    }

    public function test_reverse_batch_throws_if_period_locked(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $this->period->update(['status' => 'locked']);

        $this->expectException(\RuntimeException::class);
        $this->service->reverseBatch($batch, $this->user->id, 'Không thể đảo');
    }

    public function test_reverse_batch_throws_if_not_posted(): void
    {
        $this->makeRevenueCogs();
        $batch = $this->service->closeWithBatch('2026-06', $this->user->id);
        $this->service->reverseBatch($batch, $this->user->id, 'Lần 1');

        $this->expectException(\RuntimeException::class);
        $this->service->reverseBatch($batch->fresh(), $this->user->id, 'Lần 2');
    }
}
