<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-deploy audit §10 — edge case cho balance_source='calculated' (CompanyCashFlowReportService
 * chưa có nguồn "số dư xác nhận từ ngân hàng", số dư luôn TÍNH từ bank_transactions). Không build
 * bank balance integration ở phase này — chỉ đảm bảo không crash, KPI nhất quán, warning không
 * biến mất trong các tình huống dữ liệu thực tế hay gặp.
 */
class CompanyCashFlowBalanceEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private CompanyCashFlowReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $admin->roles()->sync([$adminRole->id]);
        $this->actingAs($admin);
        $this->report = app(CompanyCashFlowReportService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(string $name, float $opening = 0): BankAccount
    {
        return BankAccount::create([
            'name' => $name, 'bank_name' => $name, 'account_number' => '00'.rand(100000, 999999),
            'opening_balance' => $opening, 'is_active' => true,
        ]);
    }

    private function tx(BankAccount $acc, string $date, float $debit = 0, float $credit = 0): BankTransaction
    {
        return BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => $date,
            'description' => 'GD edge case', 'debit' => $debit, 'credit' => $credit,
        ]);
    }

    /** Case A — không có "opening balance" lịch sử đầy đủ (mặc định 0), chỉ có giao dịch trước kỳ. */
    public function test_case_a_account_without_historical_opening_balance(): void
    {
        $acc = $this->account('TK-A', opening: 0);
        $this->tx($acc, '2026-08-01', credit: 10_000_000); // trước kỳ báo cáo, không có "số dư đầu" chính thức
        $this->tx($acc, '2026-09-05', credit: 5_000_000);

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);

        // opening = 0 (opening_balance) + net trước kỳ (10tr) — không crash, không bỏ sót giao dịch cũ.
        $this->assertSame(10_000_000.0, $summary['opening_balance']);
        $this->assertSame(5_000_000.0, $summary['inflow']);
        $this->assertSame(15_000_000.0, $summary['closing_balance']);
        $this->assertSame('calculated', $summary['balance_source']);
    }

    /** Case B — import bắt đầu giữa tháng: giao dịch đầu tiên của account nằm giữa kỳ lọc. */
    public function test_case_b_import_starts_mid_period(): void
    {
        $acc = $this->account('TK-B', opening: 0);
        $this->tx($acc, '2026-09-15', credit: 20_000_000); // giao dịch ĐẦU TIÊN của account, nằm giữa kỳ

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(0.0, $summary['opening_balance'], 'Không có giao dịch nào trước 2026-09-15 -> opening = opening_balance gốc = 0');
        $this->assertSame(20_000_000.0, $summary['inflow']);
        $this->assertSame(20_000_000.0, $summary['closing_balance']);
        $this->assertSame('calculated', $summary['balance_source']);
    }

    /** Case C — account mới tạo, chưa có giao dịch nào. */
    public function test_case_c_new_account_with_no_transactions_at_all(): void
    {
        $acc = $this->account('TK-C', opening: 50_000_000);

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $acc->id]);

        $this->assertSame(50_000_000.0, $summary['opening_balance']);
        $this->assertSame(0.0, $summary['inflow']);
        $this->assertSame(0.0, $summary['outflow']);
        $this->assertSame(50_000_000.0, $summary['closing_balance']);
        $this->assertSame('calculated', $summary['balance_source']);
    }

    /** Case D — account có giao dịch nhưng KHÔNG có giao dịch nào rơi vào kỳ đang xem. */
    public function test_case_d_account_with_transactions_outside_period(): void
    {
        $acc = $this->account('TK-D', opening: 0);
        $this->tx($acc, '2026-07-10', credit: 30_000_000); // tháng 7, ngoài kỳ tháng 9 đang xem
        $this->tx($acc, '2026-11-10', debit: 5_000_000);   // tháng 11, cũng ngoài kỳ

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $acc->id]);

        // Giao dịch tháng 11 (SAU kỳ) không được tính vào cả opening lẫn closing của kỳ tháng 9.
        $this->assertSame(30_000_000.0, $summary['opening_balance']);
        $this->assertSame(0.0, $summary['inflow']);
        $this->assertSame(0.0, $summary['outflow']);
        $this->assertSame(30_000_000.0, $summary['closing_balance']);
    }

    /** Case E — nhiều account, chỉ 1 account có giao dịch trong kỳ (view toàn công ty, không lọc account). */
    public function test_case_e_multiple_accounts_only_one_has_transactions_in_period(): void
    {
        $active = $this->account('TK-E-active', opening: 10_000_000);
        $idle1 = $this->account('TK-E-idle1', opening: 5_000_000);
        $idle2 = $this->account('TK-E-idle2', opening: 0); // account mới, không giao dịch

        $this->tx($active, '2026-09-05', credit: 8_000_000);

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);

        // Consolidated: opening = tổng opening_balance của CẢ 3 account (idle vẫn cộng vào tổng).
        $this->assertSame(15_000_000.0, $summary['opening_balance']);
        $this->assertSame(8_000_000.0, $summary['inflow']);
        $this->assertSame(23_000_000.0, $summary['closing_balance']);
        $this->assertSame('calculated', $summary['balance_source']);

        // Lọc riêng account idle2 (chưa từng có giao dịch) — không được throw exception.
        $idleSummary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $idle2->id]);
        $this->assertSame(0.0, $idleSummary['opening_balance']);
        $this->assertSame(0.0, $idleSummary['inflow']);
        $this->assertSame(0.0, $idleSummary['closing_balance']);
    }
}
