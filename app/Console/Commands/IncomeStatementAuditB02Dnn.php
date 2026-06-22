<?php

namespace App\Console\Commands;

use App\Services\Accounting\IncomeStatementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IncomeStatementAuditB02Dnn extends Command
{
    protected $signature   = 'income-statement:audit-b02-dnn {--year= : Năm tài chính (mặc định năm hiện tại)}';
    protected $description = 'Kiểm tra tính nhất quán báo cáo kết quả hoạt động kinh doanh B02-DNN';

    public function handle(IncomeStatementService $svc): int
    {
        $year   = (int) ($this->option('year') ?? now()->year);
        $from   = "{$year}-01-01";
        $to     = "{$year}-12-31";
        $errors = 0;

        $this->info("=== B02-DNN Audit — Năm {$year} ===");

        // A1: Bút toán 511 draft chưa posted
        $draft511 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'draft')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', 'LIKE', '511%')
            ->count();
        if ($draft511 > 0) {
            $this->warn("  [A1] {$draft511} bút toán TK 511 chưa posted — doanh thu chưa đầy đủ");
            $errors++;
        } else {
            $this->line('  [A1] OK — Không có bút toán TK 511 nào ở trạng thái draft');
        }

        // A2: Bút toán cancelled/reversed vẫn xuất hiện (kiểm tra thiết kế)
        $cancelledPosted = DB::table('journal_entries')
            ->whereIn('status', ['cancelled', 'reversed'])
            ->whereBetween('entry_date', [$from, $to])
            ->whereExists(function ($q) {
                $q->from('journal_entry_lines')
                  ->whereColumn('journal_entry_id', 'journal_entries.id');
            })
            ->where(function ($q) {
                $q->where(fn($q2) => $q2->where('status', 'cancelled'))
                  ->orWhere(fn($q2) => $q2->where('status', 'reversed'));
            })
            ->count();
        $this->line("  [A2] Kiểm tra: {$cancelledPosted} bút toán cancelled/reversed (không vào báo cáo vì chỉ lấy posted)");

        // A3: Hóa đơn bán hàng có JE 511 nhưng không có JE 632
        $invoicesWithRevNoCogs = DB::table('invoices as inv')
            ->whereNotIn('inv.status', ['draft', 'cancelled'])
            ->whereBetween('inv.issue_date', [$from, $to])
            ->whereExists(function ($q) {
                $q->from('journal_entries as je')
                  ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                  ->whereColumn('je.reference_id', 'inv.id')
                  ->where('je.reference_type', 'LIKE', '%Invoice%')
                  ->where('je.status', 'posted')
                  ->where('jel.account_code', 'LIKE', '511%');
            })
            ->whereNotExists(function ($q) {
                $q->from('journal_entries as je')
                  ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                  ->whereColumn('je.reference_id', 'inv.id')
                  ->where('je.reference_type', 'LIKE', '%Invoice%')
                  ->where('je.status', 'posted')
                  ->where('jel.account_code', 'LIKE', '632%');
            })
            ->count();
        if ($invoicesWithRevNoCogs > 0) {
            $this->warn("  [A3] {$invoicesWithRevNoCogs} hóa đơn có doanh thu TK 511 nhưng chưa có giá vốn TK 632 → Thiếu COGS");
            $errors++;
        } else {
            $this->line('  [A3] OK — Các hóa đơn đều có bút toán giá vốn 632 tương ứng');
        }

        // A4: TK 632 không có chứng từ nguồn (reference_type null)
        $cogs632NoRef = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', 'LIKE', '632%')
            ->where('jel.debit', '>', 0)
            ->whereNull('je.reference_type')
            ->count();
        if ($cogs632NoRef > 0) {
            $this->warn("  [A4] {$cogs632NoRef} dòng Dr TK 632 không có chứng từ nguồn (reference_type null)");
        } else {
            $this->line('  [A4] OK — Tất cả TK 632 có chứng từ nguồn');
        }

        // A5: TK 642 không phân loại (không phải 6421/6422)
        $unclassified642 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', '642')
            ->where('jel.debit', '>', 0)
            ->count();
        if ($unclassified642 > 0) {
            $this->warn("  [A5] {$unclassified642} dòng Dr TK 642 (tổng hợp) — nên dùng 6421/6422 để chi tiết");
        } else {
            $this->line('  [A5] OK — TK 642 đều phân loại chi tiết');
        }

        // A6: TK 635 nhưng mã 23 = 0
        $fromC = Carbon::parse($from);
        $toC   = Carbon::parse($to);
        $amt22 = $svc->getLineAmount('22', $fromC, $toC);
        $amt23 = $svc->getLineAmount('23', $fromC, $toC);
        if ($amt22 > 0 && $amt23 === 0.0) {
            $this->warn('  [A6] Chi phí tài chính (TK 635) = ' . number_format($amt22)
                . ' nhưng mã 23 (lãi vay) = 0 — chưa xác định được phần chi phí lãi vay');
        } else {
            $this->line('  [A6] Mã 22 (CP tài chính): ' . number_format($amt22) . ' · Mã 23 (lãi vay): ' . number_format($amt23));
        }

        // A7: Không double-count kết chuyển 911 (thông tin)
        $has911 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', '911')
            ->exists();
        if ($has911) {
            $this->line('  [A7] Có bút toán kết chuyển 911 trong kỳ — báo cáo dùng phát sinh thuần nên không bị cộng trùng');
        } else {
            $this->line('  [A7] Chưa có bút toán kết chuyển 911');
        }

        // A8: Kiểm tra công thức
        $report = $svc->getReport($year);
        $errs   = $svc->validateFormulas($report['rows']);
        if ($errs) {
            foreach ($errs as $e) {
                $this->error("  [A8] Lỗi công thức: {$e}");
                $errors++;
            }
        } else {
            $rows = collect($report['rows'])->keyBy('code');
            $this->line('  [A8] OK — Công thức 10/20/30/40/50/60 đều đúng');
            $this->line('       Mã 60 (LNST): ' . number_format($rows['60']['curr'] ?? 0));
        }

        // A9: Năm nay/năm trước lấy đúng kỳ
        $rows = collect($report['rows'])->keyBy('code');
        $this->line("  [A9] Năm nay ({$year}): Mã 01=" . number_format($rows['01']['curr'] ?? 0)
            . " · Năm trước (" . ($year - 1) . "): Mã 01=" . number_format($rows['01']['prev'] ?? 0));

        $this->newLine();
        if ($errors === 0) {
            $this->info('✓ Audit passed — Không phát hiện lỗi nghiêm trọng.');
        } else {
            $this->warn("✗ Audit phát hiện {$errors} vấn đề cần xem xét.");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
