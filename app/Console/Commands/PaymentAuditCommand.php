<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra tính nhất quán nghiệp vụ thu/chi tiền theo Section XII của spec TT133.
 *
 * Chạy: php artisan payments:audit [--from=2026-01-01] [--fund=1]
 */
class PaymentAuditCommand extends Command
{
    protected $signature = 'payments:audit
                            {--from= : Từ ngày (YYYY-MM-DD)}
                            {--to=   : Đến ngày (YYYY-MM-DD)}
                            {--fund= : Giới hạn theo fund_id}
                            {--fix   : Hiển thị gợi ý sửa (không tự sửa)}';

    protected $description = 'Rà soát nhất quán thu/chi tiền: JE, phiếu thu/chi, sổ quỹ, tài khoản cha.';

    private int $errorCount = 0;
    private int $warningCount = 0;

    public function handle(): int
    {
        $from   = $this->option('from');
        $to     = $this->option('to');
        $fundId = $this->option('fund');

        $this->info('Đang kiểm tra nhất quán nghiệp vụ thu/chi tiền...');
        if ($from || $to) {
            $this->line("  Phạm vi: " . ($from ?? '*') . " → " . ($to ?? '*'));
        }
        $this->newLine();

        $this->checkP1InvoicePaymentsWithoutJE($from, $to, $fundId);
        $this->checkP2PurchasePaymentsWithoutJE($from, $to, $fundId);
        $this->checkP3PayrollPaidWithoutJE($from, $to);
        $this->checkP4CashVouchersWithoutJE($from, $to, $fundId);
        $this->checkP5ParentAccountInJE($from, $to);
        $this->checkP6DuplicatePayments($from, $to);
        $this->checkP7VoidedJEButActivePayment($from, $to);
        $this->checkP8FundBalanceMismatch($fundId);
        $this->checkP9JEAmountVsPaymentAmount($from, $to);

        $this->newLine();
        $this->line(str_repeat('─', 60));

        if ($this->errorCount === 0 && $this->warningCount === 0) {
            $this->info('✓ Không phát hiện vấn đề nào.');
        } else {
            if ($this->errorCount > 0) {
                $this->error("  Lỗi nghiêm trọng: {$this->errorCount}");
            }
            if ($this->warningCount > 0) {
                $this->warn("  Cảnh báo: {$this->warningCount}");
            }
        }

        return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─── P1: Invoice payments không có JE hợp lệ ──────────────────────────────

    private function checkP1InvoicePaymentsWithoutJE(?string $from, ?string $to, ?string $fundId): void
    {
        $query = DB::table('payments')
            ->leftJoin('journal_entries', function ($j) {
                $j->whereIn('journal_entries.reference_type', ['payment', 'App\\Models\\Payment'])
                  ->whereColumn('journal_entries.reference_id', 'payments.id')
                  ->whereIn('journal_entries.status', ['posted']);
            })
            ->whereNull('journal_entries.id')
            ->select('payments.id', 'payments.invoice_id', 'payments.amount', 'payments.payment_date', 'payments.fund_id');

        if ($from) $query->where('payments.payment_date', '>=', $from);
        if ($to)   $query->where('payments.payment_date', '<=', $to);
        if ($fundId) $query->where('payments.fund_id', $fundId);

        $rows = $query->get();

        $this->printSection('P1', 'Thu tiền KH không có JE posted', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("payments.id={$r->id} | invoice_id={$r->invoice_id} | {$r->payment_date} | " . number_format($r->amount));
        }
        if (count($rows) > 10) {
            $this->warn("    ... và " . (count($rows) - 10) . " bản ghi khác.");
        }
    }

    // ─── P2: Purchase invoice payments không có JE hợp lệ ─────────────────────

    private function checkP2PurchasePaymentsWithoutJE(?string $from, ?string $to, ?string $fundId): void
    {
        $query = DB::table('purchase_invoice_payments')
            ->leftJoin('journal_entries', function ($j) {
                $j->where('journal_entries.reference_type', 'purchase_invoice_payment')
                  ->whereColumn('journal_entries.reference_id', 'purchase_invoice_payments.id')
                  ->whereIn('journal_entries.status', ['posted']);
            })
            ->where('purchase_invoice_payments.status', '!=', 'voided')
            ->whereNull('journal_entries.id')
            ->select('purchase_invoice_payments.id', 'purchase_invoice_payments.purchase_invoice_id',
                     'purchase_invoice_payments.amount', 'purchase_invoice_payments.payment_date');

        if ($from) $query->where('purchase_invoice_payments.payment_date', '>=', $from);
        if ($to)   $query->where('purchase_invoice_payments.payment_date', '<=', $to);
        if ($fundId) $query->where('purchase_invoice_payments.fund_id', $fundId);

        $rows = $query->get();

        $this->printSection('P2', 'Thanh toán NCC không có JE posted', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("pip.id={$r->id} | pi_id={$r->purchase_invoice_id} | {$r->payment_date} | " . number_format($r->amount));
        }
    }

    // ─── P3: Payroll items paid nhưng salary_journal_entry_id null ─────────────

    private function checkP3PayrollPaidWithoutJE(?string $from, ?string $to): void
    {
        $query = DB::table('payroll_items')
            ->where('status', 'paid')
            ->whereNull('salary_journal_entry_id');

        if ($from) $query->where('paid_at', '>=', $from);
        if ($to)   $query->where('paid_at', '<=', $to);

        $rows = $query->get(['id', 'payroll_id', 'employee_id', 'net_salary', 'paid_at']);

        $this->printSection('P3', 'Chi lương không có JE (salary_journal_entry_id null)', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("item.id={$r->id} | payroll_id={$r->payroll_id} | employee_id={$r->employee_id} | " . number_format($r->net_salary));
        }
    }

    // ─── P4: CashVoucher confirmed nhưng không có JE ──────────────────────────

    private function checkP4CashVouchersWithoutJE(?string $from, ?string $to, ?string $fundId): void
    {
        $query = DB::table('cash_vouchers')
            ->leftJoin('journal_entries', function ($j) {
                $j->where('journal_entries.reference_type', 'cash_voucher')
                  ->whereColumn('journal_entries.reference_id', 'cash_vouchers.id')
                  ->whereIn('journal_entries.status', ['posted']);
            })
            ->where('cash_vouchers.status', 'confirmed')
            ->whereNull('journal_entries.id')
            ->select('cash_vouchers.id', 'cash_vouchers.code', 'cash_vouchers.type',
                     'cash_vouchers.amount', 'cash_vouchers.voucher_date', 'cash_vouchers.fund_id');

        if ($from) $query->where('cash_vouchers.voucher_date', '>=', $from);
        if ($to)   $query->where('cash_vouchers.voucher_date', '<=', $to);
        if ($fundId) $query->where('cash_vouchers.fund_id', $fundId);

        $rows = $query->get();

        $this->printSection('P4', 'Phiếu thu/chi confirmed không có JE', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("{$r->code} | {$r->type} | {$r->voucher_date} | " . number_format($r->amount));
        }
    }

    // ─── P5: Dùng TK tổng hợp trong JE thanh toán (111, 112, 331, 131, 334) ──

    private function checkP5ParentAccountInJE(?string $from, ?string $to): void
    {
        $parentCodes = ['111', '112', '131', '331', '334'];

        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_code', $parentCodes)
            ->where('journal_entries.status', 'posted')
            ->whereIn('journal_entries.reference_type', [
                'payment', 'purchase_invoice_payment', 'cash_voucher',
                'App\\Models\\Payment', 'App\\Models\\CashVoucher',
            ])
            ->select('journal_entries.id', 'journal_entries.code', 'journal_entries.entry_date',
                     'journal_entry_lines.account_code',
                     DB::raw('journal_entry_lines.debit + journal_entry_lines.credit as amount'));

        if ($from) $query->where('journal_entries.entry_date', '>=', $from);
        if ($to)   $query->where('journal_entries.entry_date', '<=', $to);

        $rows = $query->get();

        $this->printSection('P5', 'JE thanh toán dùng TK tổng hợp (' . implode('/', $parentCodes) . ')', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("je.id={$r->id} {$r->code} | TK {$r->account_code} | {$r->entry_date} | " . number_format($r->amount));
        }
        if ($this->option('fix') && count($rows) > 0) {
            $this->line('    → Kiểm tra fund.account_code và AccountingSettings (cash_account/bank_account) phải là TK chi tiết.');
        }
    }

    // ─── P6: Thanh toán trùng (cùng invoice, amount, date) ───────────────────

    private function checkP6DuplicatePayments(?string $from, ?string $to): void
    {
        $query = DB::table('payments')
            ->select('invoice_id', 'amount', 'payment_date', DB::raw('COUNT(*) as cnt'))
            ->groupBy('invoice_id', 'amount', 'payment_date')
            ->havingRaw('COUNT(*) > 1');

        if ($from) $query->where('payment_date', '>=', $from);
        if ($to)   $query->where('payment_date', '<=', $to);

        $rows = $query->get();

        $this->printSection('P6', 'Thu tiền KH trùng (cùng invoice+amount+date)', count($rows), 'warning');

        foreach ($rows as $r) {
            $this->printRow("invoice_id={$r->invoice_id} | " . number_format($r->amount) . " | {$r->payment_date} — {$r->cnt} lần");
        }

        // Also check purchase invoice payments
        $query2 = DB::table('purchase_invoice_payments')
            ->where('status', '!=', 'voided')
            ->select('purchase_invoice_id', 'amount', 'payment_date', DB::raw('COUNT(*) as cnt'))
            ->groupBy('purchase_invoice_id', 'amount', 'payment_date')
            ->havingRaw('COUNT(*) > 1');

        if ($from) $query2->where('payment_date', '>=', $from);
        if ($to)   $query2->where('payment_date', '<=', $to);

        $rows2 = $query2->get();
        foreach ($rows2 as $r) {
            $this->warn("    [WARNING] Thanh toán NCC trùng: pi_id={$r->purchase_invoice_id} | " . number_format($r->amount) . " | {$r->payment_date} — {$r->cnt} lần");
            $this->warningCount++;
        }
    }

    // ─── P7: Payment có JE nhưng JE đã bị void (payment vẫn active) ──────────

    private function checkP7VoidedJEButActivePayment(?string $from, ?string $to): void
    {
        $query = DB::table('payments')
            ->join('journal_entries', function ($j) {
                $j->whereIn('journal_entries.reference_type', ['payment', 'App\\Models\\Payment'])
                  ->whereColumn('journal_entries.reference_id', 'payments.id');
            })
            ->where('journal_entries.status', 'voided')
            ->select('payments.id', 'payments.invoice_id', 'payments.amount', 'payments.payment_date', 'journal_entries.id as je_id', 'journal_entries.status as je_status');

        if ($from) $query->where('payments.payment_date', '>=', $from);
        if ($to)   $query->where('payments.payment_date', '<=', $to);

        $rows = $query->get();

        $this->printSection('P7', 'Thu tiền KH còn active nhưng JE bị void', count($rows), 'critical');

        foreach ($rows->take(10) as $r) {
            $this->printRow("payment.id={$r->id} | invoice_id={$r->invoice_id} | je.id={$r->je_id} [{$r->je_status}]");
        }
    }

    // ─── P8: Số dư quỹ (trừ opening_balance) khác JE balance ───────────────────
    // Lý do trừ opening_balance: đây là field trên Fund model, không nhất thiết
    // phải có JE tương ứng. Nếu đã nhập JE đầu kỳ thì chênh lệch này = 0.

    private function checkP8FundBalanceMismatch(?string $fundId): void
    {
        $query = \App\Models\Fund::query();
        if ($fundId) $query->where('id', $fundId);
        $funds = $query->get()->filter(fn($f) => $f->account_code);

        if ($funds->isEmpty()) {
            $this->line('  [P8] ⚠ Không có quỹ nào có account_code — bỏ qua. Cấu hình account_code cho từng quỹ để kiểm tra.');
            return;
        }

        $mismatches = 0;
        foreach ($funds as $fund) {
            $jeBalance = (float) DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.status', 'posted')
                ->where('journal_entry_lines.account_code', $fund->account_code)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
                ->value('balance');

            // fund.balance() bao gồm opening_balance; JE thường không có opening JE
            $txBalance = $fund->balance() - (float) $fund->opening_balance;
            $diff = abs($jeBalance - $txBalance);

            if ($diff > 100) { // Tolerance 100 VND
                $this->warn("    [P8] Quỹ '{$fund->name}' TK {$fund->account_code}: JE={$jeBalance} | phát sinh quỹ={$txBalance} | chênh={$diff}");
                $this->warn("         (Nếu có bút toán đầu kỳ TK {$fund->account_code} thì cộng thêm opening_balance=" . $fund->opening_balance . " vào JE)");
                $this->warningCount++;
                $mismatches++;
            }
        }

        $this->printSection('P8', 'Phát sinh quỹ không khớp với JE (trừ opening_balance)', $mismatches, 'warning');
    }

    // ─── P9: Số tiền JE khác số tiền payment ─────────────────────────────────

    private function checkP9JEAmountVsPaymentAmount(?string $from, ?string $to): void
    {
        // Thu tiền KH: tổng JE debit của TK tiền phải bằng payment.amount
        $rows = DB::table('payments')
            ->join('journal_entries', function ($j) {
                $j->where('journal_entries.reference_type', 'App\\Models\\Payment')
                  ->whereColumn('journal_entries.reference_id', 'payments.id')
                  ->where('journal_entries.status', 'posted');
            })
            ->join('journal_entry_lines', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->whereRaw("journal_entry_lines.account_code NOT LIKE '1311%'")
            ->whereRaw("journal_entry_lines.account_code NOT LIKE '1312%'")
            ->whereRaw("journal_entry_lines.debit > 0")
            ->groupBy('payments.id', 'payments.amount', 'payments.payment_date', 'payments.invoice_id')
            ->havingRaw('ABS(SUM(journal_entry_lines.debit) - payments.amount) > 1')
            ->select('payments.id', 'payments.invoice_id', 'payments.amount as payment_amount',
                     'payments.payment_date', DB::raw('SUM(journal_entry_lines.debit) as je_debit'));

        if ($from) $rows->where('payments.payment_date', '>=', $from);
        if ($to)   $rows->where('payments.payment_date', '<=', $to);

        $results = $rows->get();
        $this->printSection('P9', 'Số tiền JE khác số tiền thu tiền KH', count($results), 'warning');

        foreach ($results->take(10) as $r) {
            $this->printRow("payment.id={$r->id} | {$r->payment_date} | payment={$r->payment_amount} | je_debit={$r->je_debit}");
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function printSection(string $code, string $label, int $count, string $severity): void
    {
        if ($count === 0) {
            $this->line("  [{$code}] ✓ {$label}: 0");
            return;
        }

        if ($severity === 'critical') {
            $this->error("  [{$code}] ✗ {$label}: {$count}");
            $this->errorCount += $count;
        } else {
            $this->warn("  [{$code}] ⚠ {$label}: {$count}");
            $this->warningCount += $count;
        }
    }

    private function printRow(string $msg): void
    {
        $this->line("    → {$msg}");
    }
}
