<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * AccountBalanceService — tính số dư tài khoản từ journal_entry_lines.
 *
 * Nguyên tắc:
 * - Chỉ dùng EXACT account code, KHÔNG dùng prefix matching.
 * - Chỉ lấy bút toán status = 'posted'.
 * - Mọi phép tính dựa trên raw dr/cr tổng hợp, sau đó áp dụng normal_balance direction.
 */
class AccountBalanceService
{
    /**
     * Trả về map [account_code => signed_net_balance] cho tất cả TK có phát sinh
     * tính đến $asOf (date string yyyy-mm-dd).
     *
     * signed_net = (dr - cr) với TK debit-normal
     *            = (cr - dr) với TK credit-normal
     */
    public function getAllBalancesAsOf(string $asOf): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->select(
                'jel.account_code',
                'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr')
            )
            ->groupBy('jel.account_code', 'ac.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr - (float) $r->cr
                : (float) $r->cr - (float) $r->dr;
        }
        return $result;
    }

    /**
     * Tổng signed_net_balance của đúng các TK được chỉ định (EXACT match).
     * Không prefix, không cha-con.
     */
    public function sumExact(array $balances, array $codes): float
    {
        $total = 0.0;
        foreach ($codes as $code) {
            $total += $balances[$code] ?? 0.0;
        }
        return $total;
    }

    /**
     * Tổng phần dư Nợ thực tế (dr - cr > 0) từng TK trong danh sách.
     * Dùng cho tài khoản lưỡng tính (131, 331): lấy phần TK đang dư Nợ → tài sản.
     * Không bị ảnh hưởng bởi normal_balance.
     */
    public function getDebitExcessSum(string $asOf, array $codes): float
    {
        if (empty($codes)) {
            return 0.0;
        }

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('jel.account_code', $codes)
            ->select(
                'jel.account_code',
                DB::raw('SUM(jel.debit) - SUM(jel.credit) as net')
            )
            ->groupBy('jel.account_code')
            ->havingRaw('(SUM(jel.debit) - SUM(jel.credit)) > 0')
            ->get();

        return (float) $rows->sum('net');
    }

    /**
     * Tổng phần dư Có thực tế (cr - dr > 0) từng TK trong danh sách.
     * Dùng cho tài khoản lưỡng tính (131, 331): lấy phần TK đang dư Có → nợ phải trả.
     */
    public function getCreditExcessSum(string $asOf, array $codes): float
    {
        if (empty($codes)) {
            return 0.0;
        }

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->whereIn('jel.account_code', $codes)
            ->select(
                'jel.account_code',
                DB::raw('SUM(jel.credit) - SUM(jel.debit) as net')
            )
            ->groupBy('jel.account_code')
            ->havingRaw('(SUM(jel.credit) - SUM(jel.debit)) > 0')
            ->get();

        return (float) $rows->sum('net');
    }

    /**
     * Tổng dư Có từng TK trong danh sách (bỏ qua TK dư Nợ).
     * Dùng cho TK 333 (thuế): chỉ lấy phần còn phải nộp → nợ phải trả.
     * Phần dư Nợ (nộp thừa) → kế toán đưa vào tài sản thủ công.
     */
    public function getCreditOnlySum(string $asOf, array $codes): float
    {
        return $this->getCreditExcessSum($asOf, $codes);
    }

    /**
     * Kiểm tra trial balance tại ngày $asOf.
     * Tổng dr toàn bộ bút toán posted phải = tổng cr.
     */
    public function getTrialBalanceTotals(string $asOf): array
    {
        $row = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
            ->selectRaw('SUM(jel.debit) as total_dr, SUM(jel.credit) as total_cr')
            ->first();

        $totalDr = (float) ($row->total_dr ?? 0);
        $totalCr = (float) ($row->total_cr ?? 0);

        return [
            'total_debit'  => $totalDr,
            'total_credit' => $totalCr,
            'balanced'     => abs($totalDr - $totalCr) < 1,
            'difference'   => $totalDr - $totalCr,
        ];
    }

    /**
     * Phát hiện cặp TK cha-con cùng có số dư (nguy cơ cộng trùng).
     * Trả về mảng ['parent' => code, 'children' => [code, ...], 'parent_balance' => x]
     */
    public function detectParentChildDoubling(array $balances): array
    {
        $warnings = [];
        $codes = array_keys($balances);

        foreach ($codes as $parent) {
            if (abs($balances[$parent]) < 1) {
                continue;
            }
            $children = array_filter($codes, function ($c) use ($parent) {
                return $c !== $parent
                    && strlen($c) > strlen($parent)
                    && str_starts_with($c, $parent);
            });
            $children = array_values($children);
            if (!empty($children)) {
                $warnings[] = [
                    'parent'          => $parent,
                    'children'        => $children,
                    'parent_balance'  => $balances[$parent],
                ];
            }
        }

        return $warnings;
    }
}
