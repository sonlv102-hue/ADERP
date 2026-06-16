<?php

namespace App\Services\Accounting;

use App\Models\BalanceSheetAccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * FinancialPositionReportService
 *
 * Lập Báo cáo tình hình tài chính Mẫu B01a-DNN (Thông tư 133/2016/TT-BTC).
 * Không hard-code công thức — mọi mapping lấy từ config/accounting_reports_tt133.php.
 *
 * Prefix inheritance: TK con kế thừa mapping từ TK cha (vd: 1111 thừa hưởng từ 111 → mã 110).
 * Custom DB mapping: người dùng có thể map thêm TK qua bảng balance_sheet_account_mappings.
 */
class FinancialPositionReportService
{
    public function __construct(
        private readonly AccountBalanceService $balanceSvc
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    public function build(string $asOf, string $mode = 'management'): array
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

        // 3. Kiểm tra TK 421 — cảnh báo nếu chưa kết chuyển (chỉ ở chế độ chính thức)
        $retained = $balances['421'] ?? $balances['4212'] ?? $balances['4211'] ?? null;
        if ($retained === null && $mode === 'official') {
            $warnings[] = 'TK 421 chưa có số dư — có thể chưa kết chuyển kết quả kinh doanh. '
                         . 'Mã 417 (LNST chưa phân phối) sẽ = 0.';
        }

        // Kiểm tra doanh thu/chi phí chưa kết chuyển
        $unclosed = $this->detectUnclosedIncomeExpense($balances);
        if (!empty($unclosed) && $mode === 'official') {
            $warnings[] = 'Các TK doanh thu/chi phí còn số dư (chưa kết chuyển sang 911): '
                         . implode(', ', $unclosed)
                         . '. Cần kết chuyển trước khi phát hành BCTC chính thức.';
        }

        // 4. Xây dựng danh sách items hiệu quả (merge DB mappings + prefix inheritance)
        $customMappings  = $this->loadCustomMappings();
        $effectiveItems  = $this->buildEffectiveItems($cfg, $customMappings, $balances);

        // 5. Tính toán từng dòng báo cáo
        $computed = [];
        $allItems = $effectiveItems['all'];

        // Pass 1: Non-formula items
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

        // Pass 2: Formula items
        foreach ($allItems as $item) {
            if ($item['balance_side'] !== 'formula') {
                continue;
            }
            $computed[$item['item_code']] = $this->evaluateFormula(
                $item['formula'] ?? '',
                $computed
            );
        }

        // 5b. Management mode: lãi/lỗ tạm tính khi chưa kết chuyển
        $provisionalPnL = null;
        if ($mode === 'management' && !empty($unclosed)) {
            $pnl = $this->computeProvisionalPnL($balances);
            if (abs($pnl) > 1) {
                $provisionalPnL  = $pnl;
                $computed['417'] = ($computed['417'] ?? 0.0) + $pnl;
                // Re-evaluate formula items so totals (400, 500, ...) reflect the adjustment
                foreach ($allItems as $item) {
                    if ($item['balance_side'] === 'formula') {
                        $computed[$item['item_code']] = $this->evaluateFormula(
                            $item['formula'] ?? '', $computed
                        );
                    }
                }
            }
        }

        // 6. Kiểm tra TK 353 dư Nợ
        $fund353 = $balances['353'] ?? 0.0;
        if ($fund353 < -1) {
            $warnings[] = 'TK 353 (Quỹ khen thưởng, phúc lợi) đang dư Nợ '
                         . number_format(abs($fund353), 0, ',', '.') . ' đ — '
                         . 'chi vượt quỹ; cần kiểm tra trước khi phát hành báo cáo.';
        }

        // 7. Build rows
        $assetRows  = $this->buildRows($effectiveItems['assets'], $computed, 'asset');
        $sourceRows = $this->buildRows($effectiveItems['equity'], $computed, 'source');

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
            // Thêm chẩn đoán chi tiết nguyên nhân lệch
            foreach ($this->detectImbalanceReasons($computed, $trialBalance['balanced']) as $reason) {
                $warnings[] = $reason;
            }
        }

        // 8. Tài khoản có số dư nhưng chưa được map vào B01a-DNN
        $unmapped = $this->detectUnmappedAccounts($effectiveItems['all'], $balances);
        if (!empty($unmapped)) {
            $warnings[] = sprintf(
                'Có %d tài khoản có số dư nhưng chưa được map vào B01a-DNN: %s. '
                . 'Tổng giá trị: %s đ. Dùng tab "TK chưa map" để map nhanh.',
                count($unmapped),
                implode(', ', array_column($unmapped, 'code')),
                number_format(array_sum(array_map(fn($u) => abs($u['balance']), $unmapped)), 0, ',', '.')
            );
        }

        // 9. GL breakdown (drill-down cho tab Đối soát GL)
        $glBreakdown = $this->buildGlBreakdown($effectiveItems, $computed, $balances);

        return [
            'unmapped_accounts'       => $unmapped,
            'rows'                    => array_merge($assetRows, $sourceRows),
            'summary'                 => $summary,
            'warnings'                => $warnings,
            'trial_balance'           => $trialBalance,
            'as_of'                   => $asOf,
            'report_code'             => $cfg['report_code'],
            'report_name'             => $cfg['report_name'],
            'circular'                => $cfg['circular'],
            'report_mode'             => $mode,
            'provisional_pnl'         => $provisionalPnL,
            'unclosed_income_expense' => $unclosed,
            'gl_breakdown'            => $glBreakdown,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Effective items builder (DB mappings + prefix inheritance)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Merge DB custom mappings + expand items with prefix-inherited child accounts.
     * Returns ['assets' => [...], 'equity' => [...], 'all' => [...]]
     */
    private function buildEffectiveItems(array $cfg, array $customMappings, array $balances): array
    {
        // Step 1: Merge DB mappings into config items
        $assets = $this->mergeCustomMappings($cfg['assets'], $customMappings);
        $equity = $this->mergeCustomMappings($cfg['equity_liabilities'], $customMappings);

        // Step 2: Build global explicit map: account_code → [item_code => true]
        // An account can appear in multiple items (dual-nature: e.g. TK 131x in both
        // item '131' debit_detail AND item '312' credit_detail simultaneously).
        $explicitMap = [];
        foreach (array_merge($assets, $equity) as $item) {
            foreach ($item['accounts'] ?? [] as $code) {
                $explicitMap[(string)$code][$item['item_code']] = true;
            }
        }

        // Step 3: Prefix-inherit child accounts not in any explicit mapping.
        // A child is added to ALL items whose config account is its longest prefix,
        // so dual-nature items (debit_detail + credit_detail) each get the child
        // and compute the correct side independently via getDebitExcessSum / getCreditExcessSum.
        $inheritExtra    = []; // item_code => [extra child codes]
        $excludePrefixes = ['5', '6', '8'];
        $excludeSpecific = ['911', '921'];

        foreach (array_keys($balances) as $rawCode) {
            $code = (string)$rawCode;
            if (isset($explicitMap[$code])) {
                continue; // already explicitly mapped
            }
            // Skip income/expense/closing accounts
            $skip = false;
            foreach ($excludePrefixes as $p) {
                if (str_starts_with($code, $p)) { $skip = true; break; }
            }
            if ($skip || in_array($code, $excludeSpecific)) {
                continue;
            }

            // Find the longest matching prefix among explicitly mapped codes.
            // bestItemSet collects ALL items that share that prefix (supports dual-nature).
            $bestPrefix  = '';
            $bestItemSet = []; // [item_code => true]

            foreach ($explicitMap as $mapped => $itemSet) {
                if (
                    strlen($mapped) < strlen($code)
                    && str_starts_with($code, $mapped)
                    && strlen($mapped) > strlen($bestPrefix)
                ) {
                    $bestPrefix  = $mapped;
                    $bestItemSet = $itemSet;
                }
            }

            foreach (array_keys($bestItemSet) as $itemCode) {
                $inheritExtra[$itemCode][] = $code;
            }
        }

        // Step 4: Add inherited codes into respective items
        $mergeInherited = function(array $items) use ($inheritExtra): array {
            return array_map(function($item) use ($inheritExtra) {
                $extra = $inheritExtra[$item['item_code']] ?? [];
                if ($extra) {
                    $item['accounts'] = array_values(array_unique(
                        array_merge($item['accounts'] ?? [], $extra)
                    ));
                }
                return $item;
            }, $items);
        };

        $effectiveAssets = $mergeInherited($assets);
        $effectiveEquity = $mergeInherited($equity);

        return [
            'assets' => $effectiveAssets,
            'equity' => $effectiveEquity,
            'all'    => array_merge($effectiveAssets, $effectiveEquity),
        ];
    }

    private function loadCustomMappings(): array
    {
        return BalanceSheetAccountMapping::select('item_code', 'account_code')
            ->get()
            ->groupBy('item_code')
            ->map(fn($rows) => $rows->pluck('account_code')->toArray())
            ->toArray();
    }

    private function mergeCustomMappings(array $items, array $customMappings): array
    {
        return array_map(function($item) use ($customMappings) {
            $extra = $customMappings[$item['item_code']] ?? [];
            if ($extra) {
                $item['accounts'] = array_values(array_unique(
                    array_merge($item['accounts'] ?? [], $extra)
                ));
            }
            return $item;
        }, $items);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Computation
    // ─────────────────────────────────────────────────────────────────────────

    private function computeItem(string $asOf, array $balances, array $item): float
    {
        $accounts = $item['accounts'] ?? [];
        if (empty($accounts)) {
            return 0.0;
        }

        return match ($item['balance_side']) {
            'debit'         => max(0.0, $this->balanceSvc->sumExact($balances, $accounts)),
            'credit'        => $this->balanceSvc->sumExact($balances, $accounts),
            'debit_detail'  => $this->balanceSvc->getDebitExcessSum($asOf, $accounts),
            'credit_detail' => $this->balanceSvc->getCreditExcessSum($asOf, $accounts),
            'credit_only'   => $this->balanceSvc->getCreditOnlySum($asOf, $accounts),
            default         => 0.0,
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
        $levelMap = [];
        foreach ($items as $item) {
            $levelMap[$item['item_code']] = $item['parent_code'] !== null ? 2 : 1;
        }

        foreach ($items as $item) {
            $code      = $item['item_code'];
            $amount    = $computed[$code] ?? 0.0;
            $isInternal = str_contains($code, '_');

            $rows[] = [
                'item_code'         => $isInternal ? null : $code,
                'config_code'       => $code,
                'item_name'         => $item['item_name'],
                'amount'            => $amount,
                'level'             => $levelMap[$code],
                'is_total'          => in_array($code, ['200', '300', '400', '500']),
                'is_section_header' => in_array($code, ['300', '400']),
                'is_formula'        => $item['balance_side'] === 'formula',
                'negative'          => $item['negative'],
                'section'           => $section,
                'note'              => $item['note'] ?? null,
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function detectUnclosedIncomeExpense(array $balances): array
    {
        $prefixes = ['5', '6', '8', '9'];
        $unclosed = [];

        foreach ($balances as $code => $balance) {
            if (abs($balance) < 1) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $code, $prefix) && (string) $code !== '911') {
                    $unclosed[] = (string) $code;
                    break;
                }
            }
        }

        return $unclosed;
    }

    /**
     * Phát hiện tài khoản có số dư nhưng chưa map vào B01a-DNN.
     * Nhận vào $effectiveItems (đã merge DB mapping + prefix inheritance).
     */
    private function detectUnmappedAccounts(array $effectiveItems, array $balances): array
    {
        // Tập hợp tất cả account codes đã được map (bao gồm cả prefix-inherited)
        $mappedCodes = [];
        foreach ($effectiveItems as $item) {
            foreach ($item['accounts'] ?? [] as $code) {
                $mappedCodes[(string)$code] = true;
            }
        }

        $excludePrefixes = ['5', '6', '8'];
        $extraExclude    = config('accounting_reports_tt133.unmapped_exclude', []);
        $extraSpecific   = ['911', '921'];

        $unmapped = [];
        foreach ($balances as $code => $balance) {
            if (abs($balance) < 1) {
                continue;
            }
            $codeStr = (string)$code;
            if (isset($mappedCodes[$codeStr])) {
                continue;
            }

            $excluded = false;
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($codeStr, $prefix)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded || in_array($codeStr, $extraSpecific) || in_array($codeStr, $extraExclude)) {
                continue;
            }

            $unmapped[] = ['code' => $codeStr, 'balance' => $balance];
        }

        if (!empty($unmapped)) {
            $codeList = array_column($unmapped, 'code');
            $nameMap  = DB::table('account_codes')
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

    /**
     * Tính lãi/lỗ tạm tính từ TK doanh thu/chi phí chưa kết chuyển.
     * Dương = lãi, âm = lỗ.
     */
    private function computeProvisionalPnL(array $balances): float
    {
        $revenue  = 0.0;
        $expenses = 0.0;

        foreach ($balances as $code => $net) {
            $prefix = substr((string)$code, 0, 1);
            if (in_array($prefix, ['5', '7'])) {
                $revenue += $net;  // credit-normal: net > 0 = doanh thu
            } elseif (in_array($prefix, ['6', '8'])) {
                $expenses += $net; // debit-normal: net > 0 = chi phí
            }
        }

        return $revenue - $expenses;
    }

    /**
     * Drill-down từ B01a item → danh sách TK GL với số dư, để hiển thị tab Đối soát GL.
     * Mỗi item chứa: item_code, item_name, section, total (computed), accounts[].
     */
    private function buildGlBreakdown(array $effectiveItems, array $computed, array $balances): array
    {
        $allCodes = [];
        foreach (['assets', 'equity'] as $section) {
            foreach ($effectiveItems[$section] as $item) {
                if ($item['balance_side'] === 'formula') continue;
                foreach ($item['accounts'] ?? [] as $code) {
                    $allCodes[] = (string) $code;
                }
            }
        }

        $nameMap = [];
        if (!empty($allCodes)) {
            $nameMap = DB::table('account_codes')
                ->whereIn('code', array_unique($allCodes))
                ->pluck('name', 'code')
                ->all();
        }

        $result = [];
        $sectionMap = ['assets' => 'asset', 'equity' => 'source'];

        foreach ($sectionMap as $key => $sectionLabel) {
            foreach ($effectiveItems[$key] as $item) {
                if ($item['balance_side'] === 'formula') continue;
                $accounts = [];
                foreach ($item['accounts'] ?? [] as $code) {
                    $code = (string) $code;
                    $accounts[] = [
                        'code'    => $code,
                        'name'    => $nameMap[$code] ?? '—',
                        'balance' => $balances[$code] ?? 0.0,
                    ];
                }
                $result[] = [
                    'item_code'    => $item['item_code'],
                    'item_name'    => $item['item_name'],
                    'section'      => $sectionLabel,
                    'balance_side' => $item['balance_side'],
                    'total'        => $computed[$item['item_code']] ?? 0.0,
                    'accounts'     => $accounts,
                ];
            }
        }

        return $result;
    }

    /**
     * Khi báo cáo chưa cân (mã 200 ≠ 500), chẩn đoán thêm các nguyên nhân cụ thể.
     */
    private function detectImbalanceReasons(array $computed, bool $trialBalanced): array
    {
        $reasons = [];
        $cfg     = config('accounting_reports_tt133');
        $allItems = array_merge($cfg['assets'], $cfg['equity_liabilities']);

        // 1. Trial balance không cân → dữ liệu gốc sai
        if (!$trialBalanced) {
            $reasons[] = '→ Nguyên nhân 1: Có bút toán mất cân đối Nợ/Có (Trial Balance chưa cân). '
                       . 'Kiểm tra tab "Kiểm tra cân đối".';
        }

        // 2. Chỉ tiêu có giá trị âm bất thường (TK có thể map sai bên)
        foreach ($allItems as $item) {
            if ($item['balance_side'] === 'formula' || ($item['negative'] ?? false)) {
                continue;
            }
            $value = $computed[$item['item_code']] ?? 0.0;
            if ($value < -1) {
                $accounts = implode(', ', array_slice($item['accounts'] ?? [], 0, 3));
                $reasons[] = sprintf(
                    '→ Nguyên nhân: Chỉ tiêu "%s" (mã %s) có giá trị âm %s đ. '
                    . 'Kiểm tra xem TK %s có đang được map đúng bên tài sản/nguồn vốn không.',
                    $item['item_name'],
                    $item['item_code'],
                    number_format(abs($value), 0, ',', '.'),
                    $accounts ?: '(chưa có TK)'
                );
            }
        }

        // 3. Nhắc kiểm tra TK cha hạch toán trực tiếp
        $reasons[] = '→ Gợi ý: Kiểm tra xem có TK cha (như 111, 131, 331) đang bị hạch toán trực tiếp '
                   . 'song song với TK con không (tab "Kiểm tra cân đối" → cảnh báo TK cha-con).';

        return $reasons;
    }
}
