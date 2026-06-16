<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\FinancialPositionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests cho FinancialPositionReportService (B01a-DNN, TT133).
 *
 * Mỗi test tự tạo AccountCode cần thiết → không phụ thuộc seeder.
 */
class FinancialPositionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private FinancialPositionReportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->svc = new FinancialPositionReportService(new AccountBalanceService());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function seedAccount(string $code, string $type, string $normalBalance, ?string $parent = null): void
    {
        // Ensure parent exists first to avoid FK constraint violation
        if ($parent !== null) {
            AccountCode::firstOrCreate(['code' => $parent], [
                'name'           => 'TK ' . $parent,
                'type'           => $type,
                'normal_balance' => $normalBalance,
                'parent_code'    => null,
                'level'          => 3,
                'is_detail'      => false,
                'is_active'      => true,
            ]);
        }
        AccountCode::firstOrCreate(['code' => $code], [
            'name'           => 'TK ' . $code,
            'type'           => $type,
            'normal_balance' => $normalBalance,
            'parent_code'    => $parent,
            'level'          => $parent ? 4 : 3,
            'is_detail'      => true,
            'is_active'      => true,
        ]);
    }

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

    private function build(string $asOf = '2026-06-30'): array
    {
        return $this->svc->build($asOf);
    }

    private function findRow(array $report, string $itemCode): ?array
    {
        foreach ($report['rows'] as $row) {
            if (($row['config_code'] ?? $row['item_code']) === $itemCode) {
                return $row;
            }
        }
        return null;
    }

    // ─── Test TK 131 lưỡng tính ────────────────────────────────────────────

    /**
     * TC1: TK 1311 dư Nợ → Mã 131 (phải thu KH, asset).
     *      TK 1312 dư Có → Mã 312 (KH trả tiền trước, liability).
     *      Không bù trừ hai bên.
     */
    public function test_tk131_debit_goes_to_asset_credit_goes_to_liability(): void
    {
        $this->seedAccount('5111', 'revenue', 'credit');
        $this->seedAccount('1311', 'asset', 'debit', '131');
        $this->seedAccount('1312', 'asset', 'debit', '131');
        $this->seedAccount('112',  'asset', 'debit');

        // 1311: phát hành hóa đơn 5M → dư Nợ (asset)
        $this->postEntry('2026-06-01', [['1311', 5_000_000, 0], ['5111', 0, 5_000_000]]);
        // 1312: KH trả trước 2M → dư Có (liability)
        $this->postEntry('2026-06-02', [['112', 2_000_000, 0], ['1312', 0, 2_000_000]]);

        $report = $this->build();

        $row131 = $this->findRow($report, '131');   // asset
        $row312 = $this->findRow($report, '312');   // liability

        $this->assertNotNull($row131, 'Mã 131 phải xuất hiện');
        $this->assertNotNull($row312, 'Mã 312 phải xuất hiện');
        $this->assertEquals(5_000_000, $row131['amount'], 'Mã 131 = dư Nợ 1311');
        $this->assertEquals(2_000_000, $row312['amount'], 'Mã 312 = dư Có 1312');
    }

    /**
     * TC2: Không bù trừ 1311 và 1312 — tổng asset ≠ net.
     */
    public function test_tk131_no_netting_between_customers(): void
    {
        $this->seedAccount('5111', 'revenue', 'credit');
        $this->seedAccount('1311', 'asset', 'debit', '131');
        $this->seedAccount('1312', 'asset', 'debit', '131');
        $this->seedAccount('112',  'asset', 'debit');

        $this->postEntry('2026-06-01', [['1311', 10_000_000, 0], ['5111', 0, 10_000_000]]);
        $this->postEntry('2026-06-02', [['112',  3_000_000, 0],  ['1312', 0, 3_000_000]]);

        $report = $this->build();
        $row131 = $this->findRow($report, '131');
        $row312 = $this->findRow($report, '312');

        // Net = 10-3 = 7 nhưng đúng phải tách: asset=10, liability=3
        $this->assertEquals(10_000_000, $row131['amount']);
        $this->assertEquals(3_000_000,  $row312['amount']);
    }

    // ─── Test TK 331 lưỡng tính ────────────────────────────────────────────

    /**
     * TC3: TK 3311 dư Có → Mã 311 (phải trả NCC, liability).
     *      TK 3312 dư Nợ → Mã 132 (trả trước NCC, asset).
     *      Không bù trừ hai bên.
     */
    public function test_tk331_credit_goes_to_liability_debit_goes_to_asset(): void
    {
        $this->seedAccount('1561', 'asset',   'debit');
        $this->seedAccount('1331', 'asset',   'debit');
        $this->seedAccount('3311', 'liability', 'credit', '331');
        $this->seedAccount('3312', 'liability', 'credit', '331');

        // 3311: nhập hàng 8M → dư Có (AP liability)
        $this->postEntry('2026-06-01', [
            ['1561', 7_000_000, 0], ['1331', 1_000_000, 0], ['3311', 0, 8_000_000],
        ]);
        // 3312: đã trả trước NCC 3M → dư Nợ (prepaid asset)
        $this->postEntry('2026-06-02', [['3312', 3_000_000, 0], ['1561', 0, 3_000_000]]);

        $report = $this->build();

        $row311 = $this->findRow($report, '311');
        $row132 = $this->findRow($report, '132');

        $this->assertNotNull($row311);
        $this->assertNotNull($row132);
        $this->assertEquals(8_000_000, $row311['amount'], 'Mã 311 = dư Có 3311');
        $this->assertEquals(3_000_000, $row132['amount'], 'Mã 132 = dư Nợ 3312');
    }

    // ─── Test TK 421 lợi nhuận chưa phân phối ─────────────────────────────

    /**
     * TC5: TK 421/4212 dư Có → Mã 417 dương; dư Nợ → Mã 417 âm.
     */
    public function test_tk421_retained_earnings_sign(): void
    {
        $this->seedAccount('1121', 'asset',  'debit', '112');
        $this->seedAccount('411',  'equity', 'credit');
        $this->seedAccount('4212', 'equity', 'credit', '421');

        // Vốn góp 100M
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // Kết chuyển lãi 20M → 4212 dư Có 20M
        $this->postEntry('2026-06-30', [['1121', 20_000_000, 0], ['4212', 0, 20_000_000]]);

        $report  = $this->build();
        $row417  = $this->findRow($report, '417');

        $this->assertNotNull($row417);
        $this->assertEquals(20_000_000, $row417['amount'], 'Mã 417 = dư Có TK 4212');
        $this->assertTrue($report['summary']['balanced'], 'Báo cáo phải cân');
    }

    /**
     * TC6: TK 4212 dư Nợ (lỗ lũy kế) → Mã 417 âm.
     */
    public function test_tk421_debit_balance_gives_negative_code417(): void
    {
        $this->seedAccount('1121', 'asset',  'debit', '112');
        $this->seedAccount('411',  'equity', 'credit');
        $this->seedAccount('4212', 'equity', 'credit', '421');

        // Vốn góp 100M
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // Kết chuyển lỗ → 4212 dư Nợ 5M (Dr 4212 / Cr 1121 — simulate loss)
        $this->postEntry('2026-06-30', [['4212', 5_000_000, 0], ['1121', 0, 5_000_000]]);

        $report = $this->build();
        $row417 = $this->findRow($report, '417');

        $this->assertNotNull($row417);
        $this->assertLessThan(0, $row417['amount'], 'Mã 417 phải âm khi lỗ');
        $this->assertEquals(-5_000_000, $row417['amount']);
    }

    // ─── Test TSCĐ ─────────────────────────────────────────────────────────

    /**
     * TC7: TK 211 → nguyên giá (151_fa dương).
     *      TK 214 → hao mòn lũy kế âm (152_fa âm).
     *      Mã 150 = nguyên giá - hao mòn > 0.
     */
    public function test_fixed_asset_gross_and_depreciation(): void
    {
        $this->seedAccount('1121', 'asset',    'debit', '112');
        $this->seedAccount('2111', 'asset',    'debit', '211');
        $this->seedAccount('2141', 'contra',   'credit', '214');
        $this->seedAccount('6421', 'expense',  'debit');

        // Mua TSCĐ 50M
        $this->postEntry('2025-01-01', [['2111', 50_000_000, 0], ['1121', 0, 50_000_000]]);
        // Khấu hao lũy kế 10M
        $this->postEntry('2025-12-31', [['6421', 10_000_000, 0], ['2141', 0, 10_000_000]]);

        $report = $this->build();

        $row151fa = $this->findRow($report, '151_fa');
        $row152fa = $this->findRow($report, '152_fa');
        $row150   = $this->findRow($report, '150');

        $this->assertNotNull($row151fa);
        $this->assertNotNull($row152fa);
        $this->assertNotNull($row150);

        $this->assertEquals(50_000_000, $row151fa['amount'], 'Nguyên giá = 50M');
        $this->assertEquals(-10_000_000, $row152fa['amount'], 'Hao mòn lũy kế = -10M');
        $this->assertEquals(40_000_000, $row150['amount'], 'Giá trị còn lại = 40M');
    }

    // ─── Test VAT TK 133 ───────────────────────────────────────────────────

    /**
     * TC8: Dư Nợ TK 1331 → Mã 181 (thuế GTGT được khấu trừ, tài sản).
     *      Không đưa vào chi phí hay giá vốn.
     */
    public function test_tk133_debit_goes_to_code181_not_expense(): void
    {
        $this->seedAccount('1561', 'asset',    'debit');
        $this->seedAccount('1331', 'asset',    'debit', '133');
        $this->seedAccount('3311', 'liability', 'credit', '331');

        // Nhập kho: Dr 1561 (giá chưa VAT) + Dr 1331 (VAT) / Cr 3311 (gồm VAT)
        $this->postEntry('2026-06-01', [
            ['1561', 10_000_000, 0],
            ['1331',  1_000_000, 0],
            ['3311', 0, 11_000_000],
        ]);

        $report = $this->build();

        $row181 = $this->findRow($report, '181');
        $row140 = $this->findRow($report, '140');
        $row311 = $this->findRow($report, '311');

        $this->assertNotNull($row181);
        $this->assertEquals(1_000_000, $row181['amount'], 'VAT khấu trừ = 1M tại mã 181');
        $this->assertEquals(10_000_000, $row140['amount'], 'HTK = 10M (không cộng VAT)');
        $this->assertEquals(11_000_000, $row311['amount'], 'AP = 11M (gồm VAT)');
    }

    // ─── Test TK 333 chỉ lấy dư Có ────────────────────────────────────────

    /**
     * TC9: TK 3331 dư Có → Mã 313 (thuế phải nộp).
     *      TK 3333 dư Nợ (nộp thừa) → không đưa vào Mã 313.
     */
    public function test_tk333_credit_only_to_code313(): void
    {
        $this->seedAccount('3331', 'liability', 'credit', '333');
        $this->seedAccount('3333', 'liability', 'credit', '333');
        $this->seedAccount('1121', 'asset',     'debit', '112');
        $this->seedAccount('5111', 'revenue',   'credit');

        // 3331: VAT đầu ra 2M → dư Có (phải nộp)
        $this->postEntry('2026-06-01', [['5111', 0, 10_000_000], ['3331', 0, 2_000_000], ['1121', 12_000_000, 0]]);

        // 3333: nộp thừa thuế TNDN 500K → dư Nợ (nộp vượt)
        $this->postEntry('2026-06-15', [['3333', 500_000, 0], ['1121', 0, 500_000]]);

        $report = $this->build();
        $row313 = $this->findRow($report, '313');

        $this->assertNotNull($row313);
        // Chỉ lấy dư Có: 3331 = +2M; 3333 dư Nợ → 0
        $this->assertEquals(2_000_000, $row313['amount'], 'Mã 313 chỉ lấy dư Có TK 3331');
    }

    // ─── Test không cộng trùng TK cha-con ──────────────────────────────────

    /**
     * TC10: TK 411 (cha) và TK 4111 (con) chỉ nên tính 1 lần.
     *       Nếu chỉ có entries ở 4111 → mã 411 = balance 4111 (không double).
     */
    public function test_no_parent_child_double_count_for_411(): void
    {
        $this->seedAccount('1121', 'asset',  'debit', '112');
        $this->seedAccount('411',  'equity', 'credit');
        $this->seedAccount('4111', 'equity', 'credit', '411');

        // Vốn góp vào 4111 (leaf), 411 không có entries trực tiếp
        $this->postEntry('2026-01-01', [['1121', 50_000_000, 0], ['4111', 0, 50_000_000]]);

        $report  = $this->build();
        $row411  = $this->findRow($report, '411');

        $this->assertNotNull($row411);
        // Vì 411 không có entries, sumExact(['411','4111']) = 0 + 50M = 50M (đúng)
        $this->assertEquals(50_000_000, $row411['amount'], 'Mã 411 = 50M (không double)');
    }

    // ─── Test cân bằng B01a-DNN ────────────────────────────────────────────

    /**
     * TC11: Một giao dịch cơ bản — mã 200 phải = mã 500.
     */
    public function test_balance_sheet_is_balanced(): void
    {
        $this->seedAccount('1121', 'asset',  'debit', '112');
        $this->seedAccount('411',  'equity', 'credit');
        $this->seedAccount('4212', 'equity', 'credit', '421');
        $this->seedAccount('5111', 'revenue', 'credit');
        $this->seedAccount('6321', 'expense', 'debit');
        $this->seedAccount('1561', 'asset',  'debit');
        $this->seedAccount('1331', 'asset',  'debit', '133');
        $this->seedAccount('3311', 'liability', 'credit', '331');
        $this->seedAccount('3331', 'liability', 'credit', '333');

        // Vốn góp 100M
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // Nhập kho 50M + VAT 5M
        $this->postEntry('2026-02-01', [
            ['1561', 50_000_000, 0], ['1331', 5_000_000, 0], ['3311', 0, 55_000_000],
        ]);
        // Bán hàng 70M + VAT 7M
        $this->postEntry('2026-03-01', [
            ['1121', 77_000_000, 0], ['5111', 0, 70_000_000], ['3331', 0, 7_000_000],
        ]);
        // Giá vốn 50M
        $this->postEntry('2026-03-01', [['6321', 50_000_000, 0], ['1561', 0, 50_000_000]]);
        // Thanh toán NCC 55M
        $this->postEntry('2026-04-01', [['3311', 55_000_000, 0], ['1121', 0, 55_000_000]]);
        // Kết chuyển 5111 → 911
        $this->seedAccount('911', 'expense', 'debit');
        $this->postEntry('2026-06-30', [['5111', 70_000_000, 0], ['911', 0, 70_000_000]]);
        // Kết chuyển 6321 → 911
        $this->postEntry('2026-06-30', [['911', 50_000_000, 0], ['6321', 0, 50_000_000]]);
        // Kết chuyển 911 → 4212
        $this->postEntry('2026-06-30', [['911', 20_000_000, 0], ['4212', 0, 20_000_000]]);
        // Nộp VAT 3331: 7M đầu ra - 5M đầu vào = 2M
        // (VAT đầu vào đã claim riêng; giả định đơn giản là credit 3331 2M còn lại)

        $report = $this->build();

        $this->assertTrue(
            $report['summary']['balanced'],
            'Mã 200 phải = mã 500. Chênh: ' . ($report['summary']['difference'] ?? 0)
        );
        $this->assertEquals(0.0, $report['summary']['difference'], '', 1.0);
    }

    // ─── Test cảnh báo TK 421 chưa kết chuyển ──────────────────────────────

    /**
     * TC12: Chế độ chính thức — doanh thu/chi phí còn số dư → cảnh báo chưa kết chuyển.
     */
    public function test_warning_when_income_expense_not_closed(): void
    {
        $this->seedAccount('1121', 'asset',   'debit', '112');
        $this->seedAccount('5111', 'revenue', 'credit');

        $this->postEntry('2026-06-01', [['1121', 10_000_000, 0], ['5111', 0, 10_000_000]]);

        $report = $this->svc->build('2026-06-30', 'official');

        $this->assertNotEmpty($report['warnings'], 'Phải có cảnh báo ở chế độ chính thức');
        $hasUnclosedWarning = collect($report['warnings'])->contains(
            fn($w) => str_contains($w, 'chưa kết chuyển')
        );
        $this->assertTrue($hasUnclosedWarning, 'Phải cảnh báo TK 5111 chưa kết chuyển');
    }

    /**
     * TC12b: Chế độ quản trị — doanh thu/chi phí chưa kết chuyển → lãi/lỗ tạm tính, B01a vẫn cân.
     */
    public function test_management_mode_provisional_pnl_balances_report(): void
    {
        $this->seedAccount('1121', 'asset',   'debit',  '112');
        $this->seedAccount('411',  'equity',  'credit');
        $this->seedAccount('6421', 'expense', 'debit');

        // Vốn góp 100M
        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // Chi phí 30M chưa kết chuyển
        $this->postEntry('2026-06-01', [['6421', 30_000_000, 0], ['1121', 0, 30_000_000]]);

        $report = $this->svc->build('2026-06-30', 'management');

        // B01a phải cân
        $this->assertTrue($report['summary']['balanced'], 'Quản trị mode phải cân dù chưa kết chuyển');
        // provisionalPnL phải âm (lỗ 30M)
        $this->assertNotNull($report['provisional_pnl']);
        $this->assertEqualsWithDelta(-30_000_000, $report['provisional_pnl'], 1.0);
        // unclosedIncomeExpense phải có 6421
        $this->assertContains('6421', $report['unclosed_income_expense']);
    }

    // ─── Test Trial Balance ─────────────────────────────────────────────────

    /**
     * TC13: Trial Balance cân (tổng Dr = tổng Cr).
     */
    public function test_trial_balance_is_balanced_for_valid_entries(): void
    {
        $this->seedAccount('1121', 'asset',  'debit', '112');
        $this->seedAccount('411',  'equity', 'credit');

        $this->postEntry('2026-01-01', [['1121', 50_000_000, 0], ['411', 0, 50_000_000]]);

        $report = $this->build();

        $this->assertTrue($report['trial_balance']['balanced']);
        $this->assertEquals(
            $report['trial_balance']['total_debit'],
            $report['trial_balance']['total_credit']
        );
    }

    // ─── Test prefix inheritance cho tài khoản lưỡng tính ─────────────────

    /**
     * TC15: TK con kế thừa prefix từ TK cha có balance_side = debit_detail.
     *
     * Items 131 (debit_detail) và 312 (credit_detail) đều chứa ['131', '1311', '1312', '1318'].
     * TK 13111 (con của 1311) và TK 13112 (con của 1312) không có trong config —
     * chúng phải được prefix-inherit vào CẢ HAI items.
     *
     * Mong muốn: item 131 = 5M (Dr 13111), item 312 = 3M (Cr 13112) — không lấy net.
     */
    public function test_prefix_inherited_child_of_debit_detail_parent_is_split_correctly(): void
    {
        $this->seedAccount('5111',  'revenue', 'credit');
        $this->seedAccount('1121',  'asset',   'debit',  '112');
        $this->seedAccount('131',   'asset',   'debit');
        $this->seedAccount('1311',  'asset',   'debit',  '131');
        $this->seedAccount('1312',  'asset',   'debit',  '131');
        $this->seedAccount('13111', 'asset',   'debit',  '1311');
        $this->seedAccount('13112', 'asset',   'debit',  '1312');

        // 13111: dư Nợ 5M (phải thu KH A)
        $this->postEntry('2026-06-01', [['13111', 5_000_000, 0], ['5111', 0, 5_000_000]]);
        // 13112: dư Có 3M (KH B trả tiền trước)
        $this->postEntry('2026-06-02', [['1121', 3_000_000, 0], ['13112', 0, 3_000_000]]);

        $report = $this->build();
        $row131 = $this->findRow($report, '131');
        $row312 = $this->findRow($report, '312');

        $this->assertNotNull($row131, 'Mã 131 phải xuất hiện');
        $this->assertNotNull($row312, 'Mã 312 phải xuất hiện');

        $this->assertEquals(5_000_000, $row131['amount'],
            'Mã 131 debit_detail: lấy dư Nợ 13111, loại 13112 dư Có (không net)');
        $this->assertEquals(3_000_000, $row312['amount'],
            'Mã 312 credit_detail: lấy dư Có 13112, loại 13111 dư Nợ (không net)');

        // Phát hiện net sai: 5M - 3M = 2M
        $this->assertNotEquals(2_000_000, $row131['amount'],
            'Mã 131 không được lấy net balance');
    }

    /**
     * TC16: TK con kế thừa prefix từ TK cha có balance_side = credit_detail.
     *
     * Items 311 (credit_detail) và 132 (debit_detail) đều chứa ['331', '3311', '3312', '3318'].
     * TK 33111 (con của 3311) và TK 33121 (con của 3312) không có trong config.
     *
     * Mong muốn: item 311 = 8M (Cr 33111), item 132 = 4M (Dr 33121) — không net.
     */
    public function test_prefix_inherited_child_of_credit_detail_parent_is_split_correctly(): void
    {
        $this->seedAccount('1121',  'asset',     'debit',  '112');
        $this->seedAccount('411',   'equity',    'credit');
        $this->seedAccount('1561',  'asset',     'debit');
        $this->seedAccount('331',   'liability', 'credit');
        $this->seedAccount('3311',  'liability', 'credit', '331');
        $this->seedAccount('3312',  'liability', 'credit', '331');
        $this->seedAccount('33111', 'liability', 'credit', '3311');
        $this->seedAccount('33121', 'liability', 'credit', '3312');

        $this->postEntry('2026-01-01', [['1121', 100_000_000, 0], ['411', 0, 100_000_000]]);
        // 33111: dư Có 8M (còn nợ NCC A)
        $this->postEntry('2026-06-01', [['1561', 8_000_000, 0], ['33111', 0, 8_000_000]]);
        // 33121: dư Nợ 4M (đã trả trước NCC B)
        $this->postEntry('2026-06-02', [['33121', 4_000_000, 0], ['1121', 0, 4_000_000]]);

        $report = $this->build();
        $row311 = $this->findRow($report, '311');
        $row132 = $this->findRow($report, '132');

        $this->assertNotNull($row311, 'Mã 311 phải xuất hiện');
        $this->assertNotNull($row132, 'Mã 132 phải xuất hiện');

        $this->assertEquals(8_000_000, $row311['amount'],
            'Mã 311 credit_detail: lấy dư Có 33111, loại 33121 dư Nợ (không net)');
        $this->assertEquals(4_000_000, $row132['amount'],
            'Mã 132 debit_detail: lấy dư Nợ 33121, loại 33111 dư Có (không net)');
    }

    // ─── Test KPCĐ/BHXH đúng nhóm Mã 315 ──────────────────────────────────

    /**
     * TC14: TK 33821 (KPCĐ) dư Có → Mã 315 (phải trả khác), không phải mã 314.
     */
    public function test_kpcd_tk3382_goes_to_code315(): void
    {
        $this->seedAccount('6421',  'expense',   'debit');
        $this->seedAccount('33821', 'liability', 'credit', '3382');
        $this->seedAccount('1121',  'asset',     'debit', '112');

        // Trích KPCĐ DN chịu
        $this->postEntry('2026-06-30', [['6421', 200_000, 0], ['33821', 0, 200_000]]);
        // Dummy asset entry to allow balance
        $this->postEntry('2026-01-01', [['1121', 200_000, 0], ['6421', 0, 200_000]]);

        $report  = $this->build();
        $row315  = $this->findRow($report, '315');
        $row314  = $this->findRow($report, '314');

        $this->assertEquals(200_000, $row315['amount'], 'KPCĐ 33821 → mã 315');
        $this->assertEquals(0.0, $row314['amount'] ?? 0.0, 'Mã 314 không chứa KPCĐ');
    }
}
