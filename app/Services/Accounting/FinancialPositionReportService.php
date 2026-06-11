<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * FinancialPositionReportService
 *
 * Lập Báo cáo tình hình tài chính Mẫu B01a-DNN (Thông tư 133/2016/TT-BTC).
 * Không hard-code công thức — mọi mapping lấy từ config/accounting_reports_tt133.php.
 *
 * Output build():
 *   rows[]         — danh sách dòng báo cáo (asset + source)
 *   summary        — mã 200, 300, 400, 500 + balanced
 *   warnings[]     — cảnh báo (trial balance lệch, chưa kết chuyển, cha-con trùng...)
 *   trial_balance  — tổng dr, cr, balanced
 */
class FinancialPositionReportService
{
    public function __construct(
        private readonly AccountBalanceService $balanceSvc
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    public function build(string $asOf): array
    {
        $cfg      = config('accounting_reports_tt133');
        $balances = $this->balanceSvc->getAllBalancesAsOf($asOf);

        $trialBalance = $this->balanceSvc->getTrialBalanceTotals($asOf);
        $warnings     = [];

        // 1. Kiểm tra Trial Balance
        if (!$trialBalance['balanced']) {
            $warnings[] = sprintf(
                'Trial Balance chưa cân: tổng Nợ %s ≠ tổng Có %s (lệch %s đ). '
                . 'Không nên phát hành B01a-DNN trước khi tìm nguyên nhân.',
                number_format($trialBalance['total_debit'], 0, ',', '.'),
                number_format($trialBalance['total_credit'], 0, ',', '.'),
                number_format(abs($trialBalance['difference']), 0, ',', '.')
            );
        }

        // 2. Phát hiện TK cha-con cùng có số dư
        $doubleCount = $this->balanceSvc->detectParentChildDoubling($balances);
        foreach ($doubleCount as $dc) {
            $warnings[] = sprintf(
                'TK %s (số dư %s đ) và các TK chi tiết %s cùng có số dư — nguy cơ cộng trùng khi dùng prefix.',
                $dc['parent'],
                number_format(abs($dc['parent_balance']), 0, ',', '.'),
                implode(', ', $dc['children'])
            );
        }

        // 3. Kiểm tra TK 421 — cảnh báo nếu chưa kết chuyển
        $retained = $balances['421'] ?? $balances['4212'] ?? null;
        if ($retained === null) {
            $warnings[] = 'TK 421 chưa có số dư — có thể chưa kết chuyển kết quả kinh doanh. '
                         . 'Mã 417 (LNST chưa phân phối) sẽ = 0.';
        }

        // Kiểm tra doanh thu/chi phí chưa kết chuyển
        $unclosed = $this->detectUnclosedIncomeExpense($balances);
        if (!empty($unclosed)) {
            $warnings[] = 'Các TK doanh thu/chi phí còn số dư (chưa kết chuyển sang 911): '
                         . implode(', ', $unclosed)
                         . '. Mã 417 có thể chưa phản ánh đúng lợi nhuận kỳ này.';
        }

        // 4. Tính toán từng dòng báo cáo
        $computed = [];   // item_code => float value

        // Pass 1: Non-formula items
        $allItems = array_merge($cfg['assets'], $cfg['equity_liabilities']);
        foreach ($allItems as $item) {
            if ($item['balance_side'] === 'formula') {
                continue;
            }
            $value = $this->computeItem($asOf, $balances, $item);
            if ($item['negative']) {
                $value = -abs($value);
            }
            $computed[$item['item_code']] = $value;
        }

        // Pass 2: Formula items (sequential — config ensures children before parents)
        foreach ($allItems as $item) {
            if ($item['balance_side'] !== 'formula') {
                continue;
            }
            $computed[$item['item_code']] = $this->evaluateFormula(
                $item['formula'] ?? '',
                $computed
            );
        }

        // 5. Kiểm tra TK 353 dư Nợ
        $fund353 = $balances['353'] ?? 0.0;
        if ($fund353 < -1) {  // credit-normal → negative = debit excess
            $warnings[] = 'TK 353 (Quỹ khen thưởng, phúc lợi) đang dư Nợ '
                         . number_format(abs($fund353), 0, ',', '.') . ' đ — '
                         . 'chi vượt quỹ; cần kiểm tra trước khi phát hành báo cáo.';
        }

        // 6. Build rows
        $assetRows  = $this->buildRows($cfg['assets'],             $computed, 'asset');
        $sourceRows = $this->buildRows($cfg['equity_liabilities'],  $computed, 'source');

        $summary = [
            'total_assets'             => $computed['200'] ?? 0.0,
            'total_liabilities'        => $computed['300'] ?? 0.0,
            'total_equity'             => $computed['400'] ?? 0.0,
            'total_liabilities_equity' => $computed['500'] ?? 0.0,
            'balanced'                 => abs(($computed['200'] ?? 0.0) - ($computed['500'] ?? 0.0)) < 1,
            'difference'               => ($computed['200'] ?? 0.0) - ($computed['500'] ?? 0.0),
        ];

        if (!$summary['balanced']) {
            $warnings[] = sprintf(
                'B01a-DNN chưa cân: mã 200 (Tổng tài sản) = %s đ ≠ mã 500 (Tổng nguồn vốn) = %s đ. '
                . 'Chênh lệch: %s đ.',
                number_format($summary['total_assets'], 0, ',', '.'),
                number_format($summary['total_liabilities_equity'], 0, ',', '.'),
                number_format(abs($summary['difference']), 0, ',', '.')
            );
        }

        // 7. Tài khoản có số dư nhưng chưa được map vào B01a-DNN
        $unmapped = $this->detectUnmappedAccounts($cfg, $balances);
        if (!empty($unmapped)) {
            $warnings[] = sprintf(
                'Có %d tài khoản có số dư nhưng chưa được map vào B01a-DNN: %s. '
                . 'Tổng giá trị: %s đ. Kiểm tra config/accounting_reports_tt133.php.',
                count($unmapped),
                implode(', ', array_column($unmapped, 'code')),
                number_format(array_sum(array_map(fn($u) => abs($u['balance']), $unmapped)), 0, ',', '.')
            );
        }

        return [
            'unmapped_accounts' => $unmapped,  // [] nếu không có TK lệch

            'rows'          => array_merge($assetRows, $sourceRows),
            'summary'       => $summary,
            'warnings'      => $warnings,
            'trial_balance' => $trialBalance,
            'as_of'         => $asOf,
            'report_code'   => $cfg['report_code'],
            'report_name'   => $cfg['report_name'],
            'circular'      => $cfg['circular'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function computeItem(string $asOf, array $balances, array $item): float
    {
        $accounts = $item['accounts'] ?? [];
        if (empty($accounts)) {
            return 0.0;
        }

        return match ($item['balance_side']) {
            // Debit-normal asset: sumExact trả dr-cr; max(0,...) bảo vệ dữ liệu bất thường
            'debit' => max(0.0, $this->balanceSvc->sumExact($balances, $accounts)),

            // Credit-normal liability/equity: sumExact trả cr-dr; cho phép âm (417 lỗ)
            'credit' => $this->balanceSvc->sumExact($balances, $accounts),

            // Lưỡng tính: lấy phần dư Nợ từng TK (không bù trừ với dư Có)
            'debit_detail' => $this->balanceSvc->getDebitExcessSum($asOf, $accounts),

            // Lưỡng tính: lấy phần dư Có từng TK
            'credit_detail' => $this->balanceSvc->getCreditExcessSum($asOf, $accounts),

            // Chỉ lấy phần dư Có (bỏ qua dư Nợ) — dùng cho TK 333
            'credit_only' => $this->balanceSvc->getCreditOnlySum($asOf, $accounts),

            default => 0.0,
        };
    }

    private function evaluateFormula(string $formula, array $computed): float
    {
        $total = 0.0;
        foreach (explode('+', $formula) as $code) {
            $total += $computed[trim($code)] ?? 0.0;
        }
        return $total;
    }

    private function buildRows(array $items, array $computed, string $section): array
    {
        $rows = [];

        // Xác định level dựa vào parent_code
        $levelMap = [];
        foreach ($items as $item) {
            $levelMap[$item['item_code']] = $item['parent_code'] !== null ? 2 : 1;
        }

        foreach ($items as $item) {
            $code   = $item['item_code'];
            $amount = $computed[$code] ?? 0.0;

            // Ẩn các internal sub-item codes không phải mã B01a-DNN chuẩn
            // (như 151_fa, 152_fa — hiện trực tiếp trong nhóm 150)
            $isInternal = str_contains($code, '_');

            $rows[] = [
                'item_code'          => $isInternal ? null : $code,
                'config_code'        => $code,   // always present — used by tests/exports
                'item_name'          => $item['item_name'],
                'amount'             => $amount,
                'level'              => $levelMap[$code],
                'is_total'           => in_array($code, ['200', '300', '400', '500']),
                'is_section_header'  => in_array($code, ['300', '400']),
                'is_formula'         => $item['balance_side'] === 'formula',
                'negative'           => $item['negative'],
                'section'            => $section,
                'note'               => $item['note'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Phát hiện TK doanh thu/chi phí còn số dư cuối kỳ (chưa kết chuyển sang 911).
     * Theo TT133, sau khi kết chuyển, 5xxx/6xxx/8xx phải = 0.
     */
    private function detectUnclosedIncomeExpense(array $balances): array
    {
        $prefixes = ['5', '6', '8', '9'];
        $unclosed = [];

        foreach ($balances as $code => $balance) {
            if (abs($balance) < 1) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $code, $prefix) && $code !== '911') {
                    $unclosed[] = $code;
                    break;
                }
            }
        }

        return $unclosed;
    }

    /**
     * Phát hiện tài khoản có số dư nhưng chưa được map vào B01a-DNN.
     *
     * Trả về: [['code' => '...', 'balance' => float, 'name' => '...'], ...]
     * Loại trừ: TK doanh thu/chi phí (5/6/8) vì chúng thuộc báo cáo KQHĐKD, không phải B01a-DNN.
     */
    private function detectUnmappedAccounts(array $cfg, array $balances): array
    {
        // Tập hợp tất cả account codes được dùng trong config
        $mappedCodes = [];
        $allItems = array_merge($cfg['assets'] ?? [], $cfg['equity_liabilities'] ?? []);
        foreach ($allItems as $item) {
            foreach ($item['accounts'] ?? [] as $code) {
                $mappedCodes[$code] = true;
            }
        }

        // Loại trừ TK doanh thu/chi phí (thuộc KQHĐKD, không thuộc B01a-DNN)
        // Lưu ý: không loại '9' toàn bộ vì hệ thống có thể có TK 9xx là bảng tổng hợp
        $excludePrefixes = ['5', '6', '8'];
        // Thêm các TK kết chuyển cụ thể (chắc chắn không phải BS)
        $extraExcludeSpecific = ['911', '921'];
        // Các TK trong config["unmapped_exclude"] nếu có
        $extraExclude    = $cfg['unmapped_exclude'] ?? [];

        $unmapped = [];
        foreach ($balances as $code => $balance) {
            if (abs($balance) < 1) {
                continue;
            }
            // Đã được map → bỏ qua
            if (isset($mappedCodes[$code])) {
                continue;
            }
            // TK doanh thu/chi phí → bỏ qua (chúng thuộc KQHĐKD)
            $excluded = false;
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with((string) $code, $prefix)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded || in_array($code, $extraExclude) || in_array($code, $extraExcludeSpecific)) {
                continue;
            }

            $unmapped[] = [
                'code'    => (string) $code,
                'balance' => $balance,
            ];
        }

        // Bổ sung tên tài khoản từ DB (1 query)
        if (!empty($unmapped)) {
            $codeList  = array_column($unmapped, 'code');
            $nameMap   = DB::table('account_codes')
                ->whereIn('code', $codeList)
                ->pluck('name', 'code')
                ->all();
            foreach ($unmapped as &$row) {
                $row['name'] = $nameMap[$row['code']] ?? '—';
            }
            unset($row);
        }

        return $unmapped;
    }
}
