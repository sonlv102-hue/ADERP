<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan accounting:audit-voucher-listing --from=2026-01-01 --to=2026-01-31
 *
 * Kiểm tra 7 vấn đề:
 * A1 — Bút toán posted thiếu dòng (debit=0 và credit=0 trên tất cả lines)
 * A2 — Bút toán posted mà tổng Nợ ≠ tổng Có
 * A3 — Dòng thiếu account_code
 * A4 — Tài khoản tổng hợp (is_detail=false) được dùng trực tiếp
 * A5 — Bút toán cancelled/reversed mà vẫn status=posted (data inconsistency)
 * A6 — journal_entry_lines trùng lặp (cùng je_id + account_code + debit + credit + sort_order)
 * A7 — Bút toán không xác định được TK đối ứng (nhiều N nhiều C)
 */
class AuditVoucherListing extends Command
{
    protected $signature = 'accounting:audit-voucher-listing
        {--from= : Từ ngày (Y-m-d), mặc định đầu năm}
        {--to=   : Đến ngày (Y-m-d), mặc định hôm nay}';

    protected $description = 'Kiểm tra tính nhất quán bảng kê chứng từ (journal_entries + lines)';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->startOfYear()->toDateString();
        $to   = $this->option('to')   ?: now()->toDateString();

        $this->info("Audit Bảng kê chứng từ: {$from} → {$to}");
        $this->newLine();

        $pass  = 0;
        $fail  = 0;

        // ── A1: Bút toán không có lines hoặc tất cả lines = 0 ────────────
        $a1 = DB::table('journal_entries as je')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereNotExists(function ($q) {
                $q->from('journal_entry_lines as jel')
                  ->whereColumn('jel.journal_entry_id', 'je.id')
                  ->where(function ($q2) {
                      $q2->where('jel.debit', '>', 0)->orWhere('jel.credit', '>', 0);
                  });
            })
            ->select('je.id', 'je.code', 'je.entry_date')
            ->get();

        if ($a1->isNotEmpty()) {
            $fail++;
            $this->error("[A1] {$a1->count()} bút toán posted không có dòng phát sinh:");
            foreach ($a1->take(10) as $je) {
                $this->line("     {$je->code}  {$je->entry_date}");
            }
        } else {
            $pass++;
            $this->line('<fg=green>[A1] OK</> Tất cả bút toán posted có dòng phát sinh.');
        }

        // ── A2: Tổng Nợ ≠ Tổng Có ────────────────────────────────────────
        $a2 = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('je.id', 'je.code', 'je.entry_date')
            ->havingRaw('ABS(SUM(jel.debit) - SUM(jel.credit)) >= 1')
            ->select('je.id', 'je.code', 'je.entry_date',
                     DB::raw('SUM(jel.debit) as total_debit'),
                     DB::raw('SUM(jel.credit) as total_credit'))
            ->get();

        if ($a2->isNotEmpty()) {
            $fail++;
            $this->error("[A2] {$a2->count()} bút toán mất cân (Nợ ≠ Có):");
            foreach ($a2->take(10) as $je) {
                $diff = number_format(abs($je->total_debit - $je->total_credit), 0, ',', '.');
                $this->line("     {$je->code}  {$je->entry_date}  lệch {$diff}");
            }
        } else {
            $pass++;
            $this->line('<fg=green>[A2] OK</> Tất cả bút toán cân đối Nợ/Có.');
        }

        // ── A3: Dòng thiếu account_code ───────────────────────────────────
        $a3 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereNull('jel.account_code')
            ->select('jel.id', 'je.code as je_code', 'je.entry_date')
            ->get();

        if ($a3->isNotEmpty()) {
            $fail++;
            $this->error("[A3] {$a3->count()} dòng thiếu account_code:");
            foreach ($a3->take(10) as $l) {
                $this->line("     JE {$l->je_code}  {$l->entry_date}  line_id={$l->id}");
            }
        } else {
            $pass++;
            $this->line('<fg=green>[A3] OK</> Tất cả dòng có account_code.');
        }

        // ── A4: Tài khoản tổng hợp ────────────────────────────────────────
        $a4 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('ac.is_detail', false)
            ->select('jel.account_code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('jel.account_code')
            ->get();

        if ($a4->isNotEmpty()) {
            $fail++;
            $this->error("[A4] {$a4->count()} TK tổng hợp (is_detail=false) bị dùng trực tiếp:");
            foreach ($a4 as $row) {
                $this->line("     TK {$row->account_code}  ({$row->cnt} dòng)");
            }
        } else {
            $pass++;
            $this->line('<fg=green>[A4] OK</> Không có TK tổng hợp được dùng trực tiếp.');
        }

        // ── A5: Cancelled/reversed entries vẫn status=posted ─────────────
        // (FSM đảm bảo nên thường OK, check thêm cho chắc)
        $a5 = DB::table('journal_entries as je')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereNotNull('je.voided_at')
            ->count();

        if ($a5 > 0) {
            $fail++;
            $this->error("[A5] {$a5} bút toán có voided_at nhưng vẫn status=posted (inconsistency).");
        } else {
            $pass++;
            $this->line('<fg=green>[A5] OK</> Không có bút toán voided nhưng vẫn posted.');
        }

        // ── A6: Dòng trùng lặp ────────────────────────────────────────────
        $a6 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('jel.journal_entry_id', 'jel.account_code', 'jel.debit', 'jel.credit', 'jel.sort_order')
            ->havingRaw('COUNT(*) > 1')
            ->select(DB::raw('COUNT(*) as cnt'), 'jel.journal_entry_id', 'jel.account_code', 'jel.sort_order')
            ->get();

        if ($a6->isNotEmpty()) {
            $fail++;
            $this->error("[A6] {$a6->count()} nhóm dòng có vẻ trùng lặp:");
            foreach ($a6->take(10) as $row) {
                $this->line("     je_id={$row->journal_entry_id}  TK={$row->account_code}  sort={$row->sort_order}  cnt={$row->cnt}");
            }
        } else {
            $pass++;
            $this->line('<fg=green>[A6] OK</> Không phát hiện dòng trùng lặp.');
        }

        // ── A7: Bút toán N:N (không xác định được TK đối ứng chính xác) ──
        $a7 = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('je.id', 'je.code')
            ->havingRaw('COUNT(DISTINCT CASE WHEN jel.debit  > 0 THEN jel.account_code END) > 1')
            ->havingRaw('COUNT(DISTINCT CASE WHEN jel.credit > 0 THEN jel.account_code END) > 1')
            ->select('je.id', 'je.code',
                DB::raw('COUNT(DISTINCT CASE WHEN jel.debit  > 0 THEN jel.account_code END) as debit_tks'),
                DB::raw('COUNT(DISTINCT CASE WHEN jel.credit > 0 THEN jel.account_code END) as credit_tks'))
            ->limit(20)->get();

        if ($a7->isNotEmpty()) {
            $this->warn("[A7] {$a7->count()} bút toán nhiều Nợ/nhiều Có (TK đối ứng = 'Nhiều TK' trên báo cáo):");
            foreach ($a7 as $je) {
                $this->line("     {$je->code}  debit_tks={$je->debit_tks}  credit_tks={$je->credit_tks}");
            }
            $this->line('     (Không phải lỗi — chỉ là thông tin; TK đối ứng sẽ hiện "Nhiều TK")');
        } else {
            $pass++;
            $this->line('<fg=green>[A7] OK</> Không có bút toán phức tạp N:N trong kỳ.');
        }

        // ── Summary ───────────────────────────────────────────────────────
        $this->newLine();
        $total = DB::table('journal_entries')
            ->where('status', 'posted')
            ->whereBetween('entry_date', [$from, $to])
            ->count();
        $lines = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->count();

        $this->info("Tổng: {$total} bút toán / {$lines} dòng trong kỳ.");
        $this->info("Kết quả: {$pass} check PASS  |  {$fail} check FAIL" . ($a7->isNotEmpty() ? '  |  1 INFO' : ''));

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
