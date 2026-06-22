<?php

namespace App\Console\Commands;

use App\Services\Accounting\CashFlowStatementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CashFlowAuditTt133 extends Command
{
    protected $signature   = 'cash-flow:audit-tt133 {--year= : Năm tài chính (mặc định năm hiện tại)}';
    protected $description = 'Kiểm tra tính nhất quán báo cáo lưu chuyển tiền tệ B03-DNN';

    public function handle(CashFlowStatementService $svc): int
    {
        $year = (int) ($this->option('year') ?? now()->year);
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to   = Carbon::create($year, 12, 31)->endOfDay();

        $this->info("=== Cash Flow Audit B03-DNN — Năm {$year} ===");
        $errors = 0;

        // A1: Chứng từ confirmed chưa có cash_flow_code
        $unclassified = DB::table('cash_vouchers')
            ->where('status', 'confirmed')
            ->whereNull('cash_flow_code')
            ->whereYear('voucher_date', $year)
            ->count();
        if ($unclassified > 0) {
            $this->warn("  [A1] {$unclassified} phiếu thu/chi confirmed chưa có cash_flow_code");
            $errors++;
        } else {
            $this->line('  [A1] OK — Tất cả phiếu đều có cash_flow_code');
        }

        // A2: Chứng từ cancelled/reversed vẫn vào báo cáo (không nên xảy ra vì chỉ lấy posted JE)
        $cancelledWithPostedJe = DB::table('cash_vouchers as cv')
            ->join('journal_entries as je', function ($j) {
                $j->on('je.reference_type', DB::raw("'App\\\\Models\\\\CashVoucher'"))
                  ->on('je.reference_id', '=', 'cv.id');
            })
            ->whereIn('cv.status', ['cancelled', 'reversed'])
            ->where('je.status', 'posted')
            ->whereYear('cv.voucher_date', $year)
            ->count();
        if ($cancelledWithPostedJe > 0) {
            $this->error("  [A2] {$cancelledWithPostedJe} phiếu cancelled/reversed nhưng JE vẫn posted!");
            $errors++;
        } else {
            $this->line('  [A2] OK — Không có cancelled/reversed JE vào báo cáo');
        }

        // A3: Double-count kiểm tra (cash_vouchers confirmed vs JE posted)
        $cvConfirmed = DB::table('cash_vouchers')
            ->where('status', 'confirmed')
            ->whereYear('voucher_date', $year)
            ->sum('amount');
        $this->line("  [A3] Tổng phiếu thu/chi confirmed: " . number_format($cvConfirmed, 0, ',', '.'));

        // A4: Công thức 20, 30, 40, 50, 70
        $report = $svc->getReport($year);
        $rows   = collect($report['rows'])->keyBy('code');

        $check20 = $rows['01']['curr'] + $rows['02']['curr'] + $rows['03']['curr']
                 + $rows['04']['curr'] + $rows['05']['curr'] + $rows['06']['curr'] + $rows['07']['curr'];
        $this->checkFormula('A4', 'Mã 20', $rows['20']['curr'], $check20);
        if (abs($rows['20']['curr'] - $check20) > 1) $errors++;

        $check30 = $rows['21']['curr'] + $rows['22']['curr'] + $rows['23']['curr']
                 + $rows['24']['curr'] + $rows['25']['curr'];
        $this->checkFormula('A4', 'Mã 30', $rows['30']['curr'], $check30);
        if (abs($rows['30']['curr'] - $check30) > 1) $errors++;

        $check40 = $rows['31']['curr'] + $rows['32']['curr'] + $rows['33']['curr']
                 + $rows['34']['curr'] + $rows['35']['curr'];
        $this->checkFormula('A4', 'Mã 40', $rows['40']['curr'], $check40);
        if (abs($rows['40']['curr'] - $check40) > 1) $errors++;

        $check50 = $rows['20']['curr'] + $rows['30']['curr'] + $rows['40']['curr'];
        $this->checkFormula('A4', 'Mã 50', $rows['50']['curr'], $check50);
        if (abs($rows['50']['curr'] - $check50) > 1) $errors++;

        $check70 = $rows['50']['curr'] + $rows['60']['curr'] + $rows['61']['curr'];
        $this->checkFormula('A4', 'Mã 70', $rows['70']['curr'], $check70);
        if (abs($rows['70']['curr'] - $check70) > 1) $errors++;

        // A5: Mã 70 khớp số dư TK111/112 cuối kỳ
        $rec = $report['reconciliation'];
        if ($rec['ok']) {
            $this->line('  [A5] OK — Mã 70 khớp số dư TK 111/112 cuối kỳ: ' . number_format($rec['actual_closing'], 0, ',', '.'));
        } else {
            $this->error('  [A5] Mã 70 (' . number_format($rec['reported_closing'], 0, ',', '.')
                . ') KHÔNG khớp TK 111/112 (' . number_format($rec['actual_closing'], 0, ',', '.')
                . '). Chênh lệch: ' . number_format($rec['difference'], 0, ',', '.'));
            $errors++;
        }

        // A6: Lương vào mã 03
        $salaryAmount = $svc->getLineAmount('03', $from, $to);
        $this->line('  [A6] Mã 03 (Chi lương): ' . number_format($salaryAmount, 0, ',', '.'));

        // A7: Chi NCC vào mã 02
        $supplierAmount = $svc->getLineAmount('02', $from, $to);
        $this->line('  [A7] Mã 02 (Chi NCC): ' . number_format($supplierAmount, 0, ',', '.'));

        // A8: Chi TSCĐ vào mã 21
        $fixedAssetAmount = $svc->getLineAmount('21', $from, $to);
        $this->line('  [A8] Mã 21 (Chi mua TSCĐ): ' . number_format($fixedAssetAmount, 0, ',', '.'));

        // A9: Thu/chi vay vào mã 33/34
        $borrowIn  = $svc->getLineAmount('33', $from, $to);
        $borrowOut = $svc->getLineAmount('34', $from, $to);
        $this->line('  [A9] Mã 33 (Thu vay): ' . number_format($borrowIn, 0, ',', '.'));
        $this->line('  [A9] Mã 34 (Trả nợ): ' . number_format($borrowOut, 0, ',', '.'));

        // Summary
        $this->newLine();
        if ($errors === 0) {
            $this->info("✓ Audit passed — Không phát hiện lỗi.");
        } else {
            $this->warn("✗ Audit phát hiện {$errors} vấn đề cần xem xét.");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkFormula(string $check, string $label, float $actual, float $expected): void
    {
        $diff = abs($actual - $expected);
        if ($diff > 1) {
            $this->error("  [{$check}] {$label}: báo cáo=" . number_format($actual, 0, ',', '.')
                . ', tính lại=' . number_format($expected, 0, ',', '.')
                . ', chênh=' . number_format($diff, 0, ',', '.'));
        } else {
            $this->line("  [{$check}] OK — {$label} = " . number_format($actual, 0, ',', '.'));
        }
    }
}
