<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PeriodCloseBatch;
use App\Services\AccountingService;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kết chuyển cuối kỳ TT133.
 *
 * Public API:
 *   buildClosingPlan(string $period): array       — dry-run, không write DB
 *   buildChecklist(string $period): array         — checklist nghiệp vụ định kỳ
 *   getWarnings(string $period): array            — kiểm tra cảnh báo
 *   preview(string $period): array                — plan + checklist + warnings
 *   closeWithBatch(string $period, int $userId, ?string $notes): PeriodCloseBatch
 *   reverseBatch(PeriodCloseBatch $batch, int $userId, string $reason): void
 *   buildYearEndTransfer(int $year): array        — plan chuyển 4212→4211
 *   closeYearEnd(int $year, int $userId, ?string $notes): PeriodCloseBatch
 *   close(string $period): array                  — legacy CLI compat, không tạo batch
 */
class PeriodCloseService
{
    public function __construct(private AccountingService $accounting) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public API — Preview / Plan
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tính toán kế hoạch kết chuyển cho kỳ $period (YYYY-MM).
     * Không tạo bất kỳ record nào trong DB.
     */
    public function buildClosingPlan(string $period): array
    {
        $balances   = $this->getPeriodBalances($period);
        $entryDate  = Carbon::parse("{$period}-01")->endOfMonth()->startOfDay();
        $accountMap = $this->getAccountNames(array_keys($balances));

        $revenueLines = [];
        $expenseLines = [];
        $accountLines = [];
        $incomeSections  = [];
        $expenseSections = [];
        $totalRevenue = 0;
        $totalExpense = 0;

        $pnlAccount      = AccountingSettings::get('period_close_pnl_account', '911');
        $retainedAccount = AccountingSettings::get('period_close_retained_earnings_account', '4212');

        foreach ($balances as $code => $b) {
            $name = $accountMap[$code] ?? $code;

            if ($b['type'] === 'revenue') {
                $amount = (int) round($b['total_credit'] - $b['total_debit']);
                if ($amount <= 0) continue;

                $revenueLines[] = ['account' => $code,        'debit' => $amount, 'credit' => 0,
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $revenueLines[] = ['account' => $pnlAccount,  'debit' => 0,       'credit' => $amount,
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $accountLines[] = [
                    'code'           => $code,
                    'name'           => $name,
                    'type'           => 'revenue',
                    'amount'         => $amount,
                    'journal_debit'  => $amount,
                    'journal_credit' => 0,
                    'counterpart'    => $pnlAccount,
                    'entry_text'     => "Nợ {$code} / Có {$pnlAccount}",
                ];
                $incomeSections[] = [
                    'code'           => $code,
                    'name'           => $name,
                    'total_debit'    => (int) round($b['total_debit']),
                    'total_credit'   => (int) round($b['total_credit']),
                    'closing_amount' => $amount,
                    'entry_text'     => "Nợ {$code} / Có {$pnlAccount}",
                ];
                $totalRevenue += $amount;
            }

            if ($b['type'] === 'expense') {
                $amount = (int) round($b['total_debit'] - $b['total_credit']);
                if ($amount <= 0) continue;

                $expenseLines[] = ['account' => $pnlAccount, 'debit' => $amount, 'credit' => 0,
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $expenseLines[] = ['account' => $code,        'debit' => 0,       'credit' => $amount,
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $accountLines[] = [
                    'code'           => $code,
                    'name'           => $name,
                    'type'           => 'expense',
                    'amount'         => $amount,
                    'journal_debit'  => 0,
                    'journal_credit' => $amount,
                    'counterpart'    => $pnlAccount,
                    'entry_text'     => "Nợ {$pnlAccount} / Có {$code}",
                ];
                $expenseSections[] = [
                    'code'           => $code,
                    'name'           => $name,
                    'total_debit'    => (int) round($b['total_debit']),
                    'total_credit'   => (int) round($b['total_credit']),
                    'closing_amount' => $amount,
                    'entry_text'     => "Nợ {$pnlAccount} / Có {$code}",
                ];
                $totalExpense += $amount;
            }
        }

        $profitOrLoss = $totalRevenue - $totalExpense;
        $profitLines  = [];

        if ($profitOrLoss > 0) {
            $profitLines = [
                ['account' => $pnlAccount,      'debit' => $profitOrLoss, 'credit' => 0,
                 'description' => "KC lợi nhuận kỳ {$period}"],
                ['account' => $retainedAccount, 'debit' => 0,             'credit' => $profitOrLoss,
                 'description' => "KC lợi nhuận kỳ {$period}"],
            ];
        } elseif ($profitOrLoss < 0) {
            $loss = abs($profitOrLoss);
            $profitLines = [
                ['account' => $retainedAccount, 'debit' => $loss, 'credit' => 0,
                 'description' => "KC lỗ kỳ {$period}"],
                ['account' => $pnlAccount,      'debit' => 0,     'credit' => $loss,
                 'description' => "KC lỗ kỳ {$period}"],
            ];
        }

        return [
            'period'          => $period,
            'entryDate'       => $entryDate,
            'accountLines'    => $accountLines,
            'incomeSections'  => $incomeSections,
            'expenseSections' => $expenseSections,
            'revenueLines'    => $revenueLines,
            'expenseLines'    => $expenseLines,
            'profitLines'     => $profitLines,
            'totalRevenue'    => $totalRevenue,
            'totalExpense'    => $totalExpense,
            'profitOrLoss'    => $profitOrLoss,
        ];
    }

    /**
     * Checklist nghiệp vụ định kỳ trước khi kết chuyển.
     * Mỗi item: ['key', 'label', 'status' => ok|warning|missing|info|skip|needs_review, 'message']
     */
    public function buildChecklist(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $startDate = Carbon::parse("{$period}-01")->startOfDay();
        $endDate   = $startDate->copy()->endOfMonth()->endOfDay();
        $items     = [];

        // 1. Bảng lương
        $payrollConfirmed = DB::table('payrolls')->where('period', $period)->where('status', 'confirmed')->exists();
        $payrollDraft     = DB::table('payrolls')->where('period', $period)->whereNotIn('status', ['confirmed'])->exists();
        if ($payrollConfirmed) {
            $items[] = $this->item('payroll', 'Bảng lương', 'ok', 'Đã xác nhận bảng lương trong kỳ.');
        } elseif ($payrollDraft) {
            $items[] = $this->item('payroll', 'Bảng lương', 'warning', 'Có bảng lương nháp chưa xác nhận trong kỳ.');
        } else {
            $items[] = $this->item('payroll', 'Bảng lương', 'missing', 'Chưa có bảng lương trong kỳ. Kiểm tra nếu có nhân viên.');
        }

        // 2. Khấu hao TSCĐ
        $hasActiveAssets = DB::table('fixed_assets')->where('status', 'active')->exists();
        if ($hasActiveAssets) {
            $hasDepreciation = DB::table('fixed_asset_depreciations')->where('period', $period)->exists();
            $status  = $hasDepreciation ? 'ok' : 'warning';
            $message = $hasDepreciation
                ? 'Đã trích khấu hao TSCĐ trong kỳ.'
                : 'Có TSCĐ đang sử dụng nhưng chưa trích khấu hao trong kỳ. Chạy lại "Accounting > TSCĐ > Khấu hao".';
            $items[] = $this->item('depreciation', 'Khấu hao TSCĐ (214)', $status, $message);
        } else {
            $items[] = $this->item('depreciation', 'Khấu hao TSCĐ (214)', 'skip', 'Không có TSCĐ đang sử dụng.');
        }

        // 3. Phân bổ chi phí trả trước TK 242
        $hasActivePrepaid = DB::table('prepaid_expenses')->where('status', 'active')->exists();
        if ($hasActivePrepaid) {
            $hasAllocation = DB::table('prepaid_expense_allocations')->where('period', $period)->exists();
            $status  = $hasAllocation ? 'ok' : 'warning';
            $message = $hasAllocation
                ? 'Đã phân bổ chi phí trả trước trong kỳ.'
                : 'Có chi phí trả trước (TK 242) chưa phân bổ trong kỳ.';
            $items[] = $this->item('prepaid', 'Phân bổ chi phí trả trước (242)', $status, $message);
        } else {
            $items[] = $this->item('prepaid', 'Phân bổ chi phí trả trước (242)', 'skip', 'Không có chi phí trả trước đang phân bổ.');
        }

        // 4. Bù trừ thuế GTGT (1331 / 33311)
        $vatInBalance  = $this->getAccountBalance('1331', $period, 'debit');
        $vatOutBalance = $this->getAccountBalance('33311', $period, 'credit');
        $hasVatCloseJE = JournalEntry::where('fiscal_period', $period)
            ->where('status', 'posted')
            ->where('source_type', 'vat_close')
            ->exists();

        if ($vatInBalance > 0 || $vatOutBalance > 0) {
            if ($hasVatCloseJE) {
                $items[] = $this->item('vat_close', 'Bù trừ thuế GTGT (1331/33311)', 'ok', 'Đã thực hiện bù trừ thuế GTGT trong kỳ.');
            } else {
                $msg = sprintf(
                    'Cần kiểm tra bù trừ GTGT. TK 1331 phát sinh Nợ: %s ₫ | TK 33311 phát sinh Có: %s ₫.',
                    number_format((int) $vatInBalance),
                    number_format((int) $vatOutBalance)
                );
                $items[] = $this->item('vat_close', 'Bù trừ thuế GTGT (1331/33311)', 'needs_review', $msg);
            }
        } else {
            $items[] = $this->item('vat_close', 'Bù trừ thuế GTGT (1331/33311)', 'skip', 'Không có phát sinh GTGT đầu vào/đầu ra đáng kể trong kỳ.');
        }

        // 5. Thuế TNDN tạm tính (TK 821/8211)
        $citAccount = AccountingSettings::get('cit_expense_account', '821');
        $citBalance = $this->getAccountBalance($citAccount, $period, 'debit');
        if ($citBalance > 0) {
            $items[] = $this->item('cit', "Thuế TNDN tạm tính (TK {$citAccount})", 'ok',
                "Đã hạch toán thuế TNDN: " . number_format((int) $citBalance) . " ₫.");
        } else {
            $items[] = $this->item('cit', "Thuế TNDN tạm tính (TK {$citAccount})", 'info',
                'Chưa có phát sinh thuế TNDN trong kỳ. Bỏ qua nếu doanh nghiệp không tạm tính thuế TNDN.');
        }

        // 6. Bút toán nháp trong kỳ
        $draftCount = JournalEntry::where('fiscal_period', $period)
            ->where('status', 'draft')
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'period_close'))
            ->count();
        if ($draftCount === 0) {
            $items[] = $this->item('draft_jes', 'Bút toán nháp chưa duyệt', 'ok', 'Không có bút toán nháp trong kỳ.');
        } else {
            $items[] = $this->item('draft_jes', 'Bút toán nháp chưa duyệt', 'warning',
                "{$draftCount} bút toán nháp chưa duyệt. Kết chuyển có thể thiếu phát sinh.");
        }

        // 7. TK 627 — Chi phí SX chung (cần phân bổ sang 154 trước BCTC)
        $tk627Net = $this->getAccountBalance('627', $period, 'net');
        if ($tk627Net > 0) {
            $items[] = $this->item('tk627', 'TK 627 — Chi phí SX chung', 'warning',
                'TK 627 còn số dư Nợ: ' . number_format((int) $tk627Net) . ' ₫. Cần phân bổ sang TK 154 trước khi lập BCTC chính thức.');
        } else {
            $items[] = $this->item('tk627', 'TK 627 — Chi phí SX chung', 'ok', 'TK 627 không có số dư.');
        }

        // 8. Phiếu nhập kho nháp trong tháng
        $draftEntries = DB::table('stock_entries')
            ->where('status', 'draft')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();
        if ($draftEntries > 0) {
            $items[] = $this->item('stock_entries', 'Phiếu nhập kho', 'warning',
                "{$draftEntries} phiếu nhập kho nháp chưa xác nhận.");
        } else {
            $items[] = $this->item('stock_entries', 'Phiếu nhập kho', 'ok', 'Không có phiếu nhập kho nháp trong kỳ.');
        }

        // 9. TK 154 — Chi phí dở dang (chỉ thông tin, không chuyển sang 911)
        $wipNet = $this->getAccountBalance('154', $period, 'net');
        if ($wipNet > 0) {
            $items[] = $this->item('wip', 'Chi phí dở dang (154)', 'info',
                'TK 154 dư Nợ: ' . number_format((int) $wipNet) . ' ₫. Các dự án đang thi công — không kết chuyển vào 911.');
        } else {
            $items[] = $this->item('wip', 'Chi phí dở dang (154)', 'ok', 'Không có chi phí dở dang dự án.');
        }

        return $items;
    }

    /**
     * Trả về danh sách cảnh báo trước khi kết chuyển.
     * ['type' => 'critical|warning|info', 'code' => string, 'message' => string]
     */
    public function getWarnings(string $period, ?AccountingPeriod $accountingPeriod = null): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = $accountingPeriod ?? AccountingPeriod::where('year', $year)->where('month', $month)->first();

        $warnings = [];

        if (!$ap) {
            $warnings[] = $this->warn('critical', 'PERIOD_NOT_FOUND', "Kỳ kế toán {$period} chưa được tạo.");
            return $warnings;
        }

        if ($ap->status === 'locked') {
            $warnings[] = $this->warn('critical', 'PERIOD_LOCKED', "Kỳ {$period} đã khóa. Không thể kết chuyển.");
        }

        if ($this->hasActiveBatch($ap->id)) {
            $warnings[] = $this->warn('critical', 'EXISTING_BATCH',
                "Đã tồn tại bút toán kết chuyển active trong kỳ {$period}. Hủy/đảo batch cũ trước.");
        }

        // Kiểm tra GL cân
        $glTotals = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->selectRaw('COALESCE(SUM(jl.debit),0) as total_debit, COALESCE(SUM(jl.credit),0) as total_credit')
            ->first();

        if ($glTotals && abs((float)$glTotals->total_debit - (float)$glTotals->total_credit) > 1) {
            $warnings[] = $this->warn('critical', 'GL_UNBALANCED',
                sprintf('Sổ cái không cân. Tổng Nợ: %s ₫ — Tổng Có: %s ₫.',
                    number_format((int)$glTotals->total_debit),
                    number_format((int)$glTotals->total_credit)
                ));
        }

        // Kiểm tra TK 911 tồn tại và là tài khoản chi tiết
        $pnlAccount = AccountingSettings::get('period_close_pnl_account', '911');
        $pnlCode    = DB::table('account_codes')->where('code', $pnlAccount)->first();
        if (!$pnlCode) {
            $warnings[] = $this->warn('critical', 'MISSING_ACCOUNT', "Thiếu tài khoản {$pnlAccount} (TK xác định KQKD) trong hệ thống.");
        } elseif (!$pnlCode->is_detail) {
            $warnings[] = $this->warn('critical', 'ACCOUNT_NOT_DETAIL', "TK {$pnlAccount} không phải tài khoản chi tiết. Không thể hạch toán trực tiếp.");
        }

        // Kiểm tra TK 4212 tồn tại và là tài khoản chi tiết
        $retainedAccount = AccountingSettings::get('period_close_retained_earnings_account', '4212');
        $retainedCode    = DB::table('account_codes')->where('code', $retainedAccount)->first();
        if (!$retainedCode) {
            $warnings[] = $this->warn('critical', 'MISSING_ACCOUNT', "Thiếu tài khoản {$retainedAccount} (TK lợi nhuận chưa phân phối) trong hệ thống.");
        } elseif (!$retainedCode->is_detail) {
            $warnings[] = $this->warn('critical', 'ACCOUNT_NOT_DETAIL', "TK {$retainedAccount} không phải tài khoản chi tiết. Không thể hạch toán trực tiếp.");
        }

        // Bút toán nháp
        $draftCount = JournalEntry::where('fiscal_period', $period)
            ->where('status', 'draft')
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'period_close'))
            ->count();
        if ($draftCount > 0) {
            $warnings[] = $this->warn('warning', 'DRAFT_ENTRIES',
                "Có {$draftCount} bút toán nháp chưa duyệt trong kỳ. Kết chuyển có thể thiếu phát sinh.");
        }

        // Tài khoản tổng hợp bị hạch toán trực tiếp
        $parentEntryCount = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jl.account_code')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('ac.is_detail', false)
            ->where(fn ($q) => $q->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'period_close'))
            ->count();
        if ($parentEntryCount > 0) {
            $warnings[] = $this->warn('warning', 'PARENT_ACCOUNT_ENTRIES',
                "{$parentEntryCount} dòng bút toán đang dùng tài khoản tổng hợp. Các dòng này sẽ không được kết chuyển.");
        }

        // TK 641 (TT133 không dùng)
        $tk641Count = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('jl.account_code', 'like', '641%')
            ->count();
        if ($tk641Count > 0) {
            $warnings[] = $this->warn('warning', 'TK641_EXISTS',
                "Có phát sinh TK 641 trong kỳ ({$tk641Count} dòng). TT133 dùng TK 6421. Kiểm tra lại nguồn gốc bút toán.");
        }

        // TK 521 — TT133 không dùng TK giảm trừ doanh thu riêng
        $tk521Count = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('jl.account_code', 'like', '521%')
            ->count();
        if ($tk521Count > 0) {
            $warnings[] = $this->warn('warning', 'TK521_EXISTS',
                "Có phát sinh TK 521 ({$tk521Count} dòng). TT133 ghi giảm trừ doanh thu trực tiếp vào Nợ TK 511, không dùng TK 521.");
        }

        // TK 627 còn số dư
        $tk627Net = $this->getAccountBalance('627', $period, 'net');
        if ($tk627Net > 0) {
            $warnings[] = $this->warn('warning', 'TK627_BALANCE',
                'TK 627 còn số dư Nợ: ' . number_format((int) $tk627Net) . ' ₫. Cần phân bổ sang TK 154 trước khi lập BCTC.');
        }

        // Hoa hồng
        $endOfMonth = Carbon::parse("{$period}-01")->endOfMonth()->format('Y-m-d');
        $commissionCount = DB::table('commissions')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', ["{$period}-01 00:00:00", "{$endOfMonth} 23:59:59"])
            ->count();
        if ($commissionCount > 0) {
            $warnings[] = $this->warn('info', 'COMMISSIONS_CHECK',
                "Có {$commissionCount} hoa hồng trong kỳ. Xác nhận đã hạch toán vào TK 6421 trước khi kết chuyển.");
        }

        // TK 154 dở dang
        $wipNet = $this->getAccountBalance('154', $period, 'net');
        if ($wipNet > 0) {
            $warnings[] = $this->warn('info', 'WIP_BALANCE',
                'Có chi phí dở dang TK 154 trong kỳ. TK 154 sẽ không được kết chuyển — chỉ kết chuyển sau nghiệm thu dự án.');
        }

        return $warnings;
    }

    /**
     * Dry-run: trả về plan + checklist + warnings để hiển thị trên UI.
     */
    public function preview(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        $plan      = $this->buildClosingPlan($period);
        $checklist = $this->buildChecklist($period);
        $warnings  = $this->getWarnings($period, $ap);
        $hasCritical = collect($warnings)->where('type', 'critical')->isNotEmpty();

        // Tổng hợp kết quả
        $citAccount = AccountingSettings::get('cit_expense_account', '821');
        $citBalance = $this->getAccountBalance($citAccount, $period, 'debit');
        $profitAfterTax = $plan['profitOrLoss'] - (int) $citBalance;

        return array_merge($plan, [
            'checklist'      => $checklist,
            'warnings'       => $warnings,
            'hasCritical'    => $hasCritical,
            'canClose'       => !$hasCritical && $ap && $ap->status === 'open',
            'periodStatus'   => $ap?->status ?? 'not_found',
            'result'         => [
                'totalRevenue'    => $plan['totalRevenue'],
                'totalExpense'    => $plan['totalExpense'],
                'profitOrLoss'    => $plan['profitOrLoss'],
                'citExpense'      => (int) $citBalance,
                'profitAfterTax'  => $profitAfterTax,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API — Execute / Batch
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tạo batch kết chuyển với journal entries trong một transaction.
     *
     * @throws \RuntimeException nếu có cảnh báo Critical
     */
    public function closeWithBatch(string $period, int $userId, ?string $notes = null): PeriodCloseBatch
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        if (!$ap || $ap->status !== 'open') {
            throw new \RuntimeException("Kỳ kế toán {$period} không tồn tại hoặc đã đóng/khóa.");
        }

        $warnings  = $this->getWarnings($period, $ap);
        $criticals = collect($warnings)->where('type', 'critical');
        if ($criticals->isNotEmpty()) {
            throw new \RuntimeException($criticals->pluck('message')->implode(' | '));
        }

        $plan = $this->buildClosingPlan($period);

        if (empty($plan['revenueLines']) && empty($plan['expenseLines'])) {
            throw new \RuntimeException("Không có phát sinh doanh thu/chi phí trong kỳ {$period}.");
        }

        return DB::transaction(function () use ($period, $userId, $notes, $plan, $ap) {
            $batch = PeriodCloseBatch::create([
                'code'                 => PeriodCloseBatch::generateCode($period),
                'accounting_period_id' => $ap->id,
                'fiscal_period'        => $period,
                'batch_type'           => 'monthly',
                'status'               => 'draft',
                'total_revenue'        => $plan['totalRevenue'],
                'total_expense'        => $plan['totalExpense'],
                'profit_or_loss'       => $plan['profitOrLoss'],
                'notes'                => $notes,
                'created_by'           => $userId,
            ]);

            $jeCount   = 0;
            $entryDate = $plan['entryDate'];

            if (!empty($plan['revenueLines'])) {
                $je = $this->accounting->post(
                    "Kết chuyển doanh thu kỳ {$period}", $entryDate, $plan['revenueLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            if (!empty($plan['expenseLines'])) {
                $je = $this->accounting->post(
                    "Kết chuyển chi phí kỳ {$period}", $entryDate, $plan['expenseLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            if (!empty($plan['profitLines'])) {
                $desc = $plan['profitOrLoss'] > 0
                    ? "Kết chuyển lợi nhuận kỳ {$period}"
                    : "Kết chuyển lỗ kỳ {$period}";
                $je = $this->accounting->post(
                    $desc, $entryDate, $plan['profitLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            $batch->update([
                'status'              => 'posted',
                'journal_entry_count' => $jeCount,
                'posted_by'           => $userId,
                'posted_at'           => now(),
            ]);

            return $batch;
        });
    }

    /**
     * Đảo toàn bộ batch kết chuyển trong một transaction.
     *
     * @throws \RuntimeException nếu kỳ đã khóa hoặc batch không thể đảo
     */
    public function reverseBatch(PeriodCloseBatch $batch, int $userId, string $reason): void
    {
        if ($batch->status !== 'posted') {
            throw new \RuntimeException("Chỉ có thể đảo batch ở trạng thái 'Đã kết chuyển'.");
        }

        $ap = $batch->accountingPeriod;
        if ($ap && $ap->status === 'locked') {
            throw new \RuntimeException("Kỳ {$batch->fiscal_period} đã khóa. Không thể đảo bút toán kết chuyển.");
        }

        DB::transaction(function () use ($batch, $userId, $reason) {
            $entries = $batch->journalEntries()
                ->where('status', 'posted')
                ->with('lines')
                ->get();

            foreach ($entries as $je) {
                $reversal = $this->accounting->reverse($je, $reason);
                $reversal->update(['period_close_batch_id' => $batch->id]);
            }

            $batch->update([
                'status'         => 'reversed',
                'reversed_by'    => $userId,
                'reversed_at'    => now(),
                'reverse_reason' => $reason,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Year-End Transfer (4212 → 4211)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tính kế hoạch chuyển lợi nhuận năm nay (4212) sang năm trước (4211).
     * Tính số dư 4212 từ toàn bộ lịch sử đến ngày 31/12 của năm đó.
     */
    public function buildYearEndTransfer(int $year): array
    {
        $retainedNow   = AccountingSettings::get('period_close_retained_earnings_account', '4212');
        $retainedPrior = AccountingSettings::get('period_close_prior_year_account', '4211');

        // Số dư 4212 tích luỹ đến 31/12/year (credit - debit)
        $balance = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', "{$year}-12-31")
            ->where('jl.account_code', $retainedNow)
            ->selectRaw('COALESCE(SUM(jl.credit) - SUM(jl.debit), 0) as net')
            ->value('net');

        if (abs($balance) < 1) {
            return ['year' => $year, 'balance' => 0.0, 'lines' => [],
                    'description' => "TK {$retainedNow} không có số dư cần chuyển cho năm {$year}."];
        }

        if ($balance > 0) {
            // Lãi: Dr 4212 / Cr 4211
            $lines = [
                ['account' => $retainedNow,   'debit' => (int)round($balance), 'credit' => 0,
                 'description' => "Chuyển LNST năm {$year} sang năm trước"],
                ['account' => $retainedPrior, 'debit' => 0, 'credit' => (int)round($balance),
                 'description' => "Chuyển LNST năm {$year} sang năm trước"],
            ];
            $desc = "Chuyển lợi nhuận năm {$year}: Nợ {$retainedNow} / Có {$retainedPrior}";
        } else {
            // Lỗ: Dr 4211 / Cr 4212
            $loss = (int)round(abs($balance));
            $lines = [
                ['account' => $retainedPrior, 'debit' => $loss, 'credit' => 0,
                 'description' => "Chuyển lỗ năm {$year} sang năm trước"],
                ['account' => $retainedNow,   'debit' => 0,     'credit' => $loss,
                 'description' => "Chuyển lỗ năm {$year} sang năm trước"],
            ];
            $desc = "Chuyển lỗ năm {$year}: Nợ {$retainedPrior} / Có {$retainedNow}";
        }

        return [
            'year'         => $year,
            'balance'      => $balance,
            'lines'        => $lines,
            'description'  => $desc,
            'entryDate'    => "{$year}-12-31",
            'fiscalPeriod' => "{$year}-12",
        ];
    }

    /**
     * Tạo batch chuyển lợi nhuận năm $year (4212 → 4211).
     * Dùng kỳ tháng 12 của năm đó; December phải ở trạng thái open.
     */
    public function closeYearEnd(int $year, int $userId, ?string $notes = null): PeriodCloseBatch
    {
        $decPeriod = "{$year}-12";
        $ap = AccountingPeriod::where('year', $year)->where('month', 12)->first();

        if (!$ap || $ap->status !== 'open') {
            throw new \RuntimeException("Kỳ tháng 12/{$year} không tồn tại hoặc đã đóng/khóa. Cần kỳ tháng 12 đang mở để tạo bút toán.");
        }

        if ($this->hasActiveYearEndBatch($year)) {
            throw new \RuntimeException("Đã có batch chuyển lợi nhuận năm {$year}. Hủy/đảo batch cũ trước.");
        }

        $transfer = $this->buildYearEndTransfer($year);

        if (empty($transfer['lines'])) {
            throw new \RuntimeException($transfer['description']);
        }

        $entryDate = Carbon::parse("{$year}-12-31");

        return DB::transaction(function () use ($year, $userId, $notes, $transfer, $entryDate, $ap, $decPeriod) {
            $batch = PeriodCloseBatch::create([
                'code'                 => "KC-{$year}-YE",
                'accounting_period_id' => $ap->id,
                'fiscal_period'        => $decPeriod,
                'batch_type'           => 'year_end',
                'status'               => 'draft',
                'total_revenue'        => 0,
                'total_expense'        => 0,
                'profit_or_loss'       => (int)round($transfer['balance']),
                'notes'                => $notes,
                'created_by'           => $userId,
            ]);

            $je = $this->accounting->post(
                $transfer['description'], $entryDate, $transfer['lines'],
                'accounting_period', $ap->id, false, null, 'year_open', false, $decPeriod
            );
            $je->update(['period_close_batch_id' => $batch->id]);

            $batch->update([
                'status'              => 'posted',
                'journal_entry_count' => 1,
                'posted_by'           => $userId,
                'posted_at'           => now(),
            ]);

            return $batch;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Legacy — backward compat với CLI command (không tạo batch)
    // ─────────────────────────────────────────────────────────────────────────

    /** @deprecated Dùng closeWithBatch() cho UI. Method này giữ cho CLI command. */
    public function close(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        if (!$ap || $ap->status !== 'open') {
            throw new \RuntimeException("Kỳ kế toán {$period} không tồn tại hoặc đã đóng/khóa.");
        }

        if ($this->alreadyClosed($period, $ap->id)) {
            throw new \RuntimeException("Kỳ {$period} đã được kết chuyển trước đó. Hủy bút toán kết chuyển cũ trước nếu muốn làm lại.");
        }

        $plan = $this->buildClosingPlan($period);
        $createdJes = [];

        if (!empty($plan['revenueLines'])) {
            $createdJes[] = $this->accounting->post(
                "Kết chuyển doanh thu kỳ {$period}", $plan['entryDate'], $plan['revenueLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }
        if (!empty($plan['expenseLines'])) {
            $createdJes[] = $this->accounting->post(
                "Kết chuyển chi phí kỳ {$period}", $plan['entryDate'], $plan['expenseLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }
        if (!empty($plan['profitLines'])) {
            $desc = $plan['profitOrLoss'] > 0 ? "Kết chuyển lợi nhuận kỳ {$period}" : "Kết chuyển lỗ kỳ {$period}";
            $createdJes[] = $this->accounting->post(
                $desc, $plan['entryDate'], $plan['profitLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }

        return $createdJes;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function hasActiveBatch(int $periodId): bool
    {
        return PeriodCloseBatch::where('accounting_period_id', $periodId)
            ->where('batch_type', 'monthly')
            ->whereIn('status', ['draft', 'posted'])
            ->exists();
    }

    private function hasActiveYearEndBatch(int $year): bool
    {
        return PeriodCloseBatch::where('batch_type', 'year_end')
            ->where('code', "KC-{$year}-YE")
            ->whereIn('status', ['draft', 'posted'])
            ->exists();
    }

    private function alreadyClosed(string $period, int $periodId): bool
    {
        return JournalEntry::where('source_type', 'period_close')
            ->where('reference_type', 'accounting_period')
            ->where('reference_id', $periodId)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->exists();
    }

    private function getPeriodBalances(string $period): array
    {
        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jl.account_code')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->whereIn('ac.type', ['revenue', 'expense'])
            ->where('ac.is_detail', true)
            ->where(function ($q) {
                $q->whereNull('je.source_type')
                  ->orWhereNotIn('je.source_type', ['period_close', 'year_open']);
            })
            ->groupBy('jl.account_code', 'ac.type', 'ac.normal_balance')
            ->select(
                'jl.account_code',
                'ac.type',
                'ac.normal_balance',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = [
                'type'           => $r->type,
                'normal_balance' => $r->normal_balance,
                'total_debit'    => (float) $r->total_debit,
                'total_credit'   => (float) $r->total_credit,
            ];
        }
        return $result;
    }

    /**
     * Số dư tài khoản theo phát sinh trong kỳ.
     * $side: 'debit' | 'credit' | 'net' (debit - credit)
     */
    private function getAccountBalance(string $code, string $period, string $side = 'net'): float
    {
        $row = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('jl.account_code', $code)
            ->selectRaw('COALESCE(SUM(jl.debit), 0) as total_debit, COALESCE(SUM(jl.credit), 0) as total_credit')
            ->first();

        if (!$row) return 0.0;

        return match($side) {
            'debit'  => (float) $row->total_debit,
            'credit' => (float) $row->total_credit,
            default  => (float) ($row->total_debit - $row->total_credit),
        };
    }

    private function getAccountNames(array $codes): array
    {
        if (empty($codes)) return [];
        return DB::table('account_codes')
            ->whereIn('code', $codes)
            ->pluck('name', 'code')
            ->all();
    }

    private function item(string $key, string $label, string $status, string $message): array
    {
        return compact('key', 'label', 'status', 'message');
    }

    private function warn(string $type, string $code, string $message): array
    {
        return compact('type', 'code', 'message');
    }
}
