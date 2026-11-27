<?php

namespace App\Services\Inventory\Integrity;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Đối chiếu GIÁ TRỊ tồn kho — kế toán (READ-ONLY), chạy trong cùng transaction audit toàn hệ thống.
 *  1. AVCO ↔ sổ kho kho chung (= Σ movement hiệu lực − Σ lô dự án còn lại)
 *  2. Sổ kho ↔ TK 156 (JE posted)
 *  3. Tổng tồn (AVCO + lô dự án) ↔ TK 156  (= 1 + 2, không cộng trùng)
 *  4. TK 154 từ phiếu xuất ↔ WIP từ phiếu xuất (phạm vi riêng)
 * Mỗi chênh lệch được tách thành các khoản cộng lại ĐÚNG bằng chênh lệch: khoản gắn vấn đề (giải trình), khoản dưới
 * ngưỡng, khoản chưa giải trình. Không kết luận "khớp" chỉ vì tổng lệch nhỏ — luôn hiển thị các khoản bù trừ.
 */
class IntegrityValueReconciliation
{
    private const REF_TO_SOURCE = [
        'stock_entry' => 'App\Models\StockEntry', 'stock_exit' => 'App\Models\StockExit',
        'App\Models\InventoryCount' => 'App\Models\InventoryCount', 'opening_balance' => 'App\Models\InventoryOpeningBalance',
    ];
    private const TYPE_LEVEL = ['App\Models\InventoryOpeningBalance'];   // đối chiếu theo loại (JE đầu kỳ không gắn từng dòng)
    private const RULE_LABELS = [
        'NEGATIVE_STOCK' => 'Tồn âm (AVCO = 0, sổ kho âm)', 'VALUE_MISMATCH' => 'Lệch giá trị AVCO', 'ZERO_QTY_WITH_VALUE' => 'Hết hàng còn giá trị sổ kho',
        'EXPECTED_DIFFERENCE' => 'Làm tròn (≤ 10đ)', 'MISSING_BALANCE' => 'Chưa có dòng AVCO',
    ];

    /** @param list<array> $docFindings phát hiện theo chứng từ (để nhận diện JE thủ công đã đối chiếu) */
    public function compute(Collection $rows, array $docFindings): array
    {
        $eps = IntegrityRuleCatalog::EPS_VND;
        $avco = (float) DB::table('inventory_balances')->sum('value_on_hand');
        $lot = (float) (DB::table('project_inventory_lots')->where('status', '<>', 'cancelled')->selectRaw('SUM((received_qty - issued_qty) * COALESCE(unit_cost, 0)) v')->value('v') ?? 0);
        $book = (float) DB::table('stock_movements')->where('status', 'active')->sum('amount');
        $gl156 = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')->where('l.account_code', 'like', '156%')->groupBy('l.account_code')
            ->selectRaw('l.account_code, SUM(l.debit - l.credit) v')->pluck('v', 'account_code')->map(fn ($v) => round((float) $v, 2));
        $glTotal = (float) $gl156->sum();

        $c1 = $this->comparison('avco_vs_book', '1. AVCO so với sổ kho (kho chung = sổ kho − lô dự án)', $avco, $book - $lot, $this->avcoItems($rows));
        $c2 = $this->comparison('book_vs_gl', '2. Sổ kho (movement hiệu lực) so với TK 156', $book, $glTotal, $this->glItems($docFindings));
        $c3 = $this->comparison('stock_vs_gl', '3. Tổng giá trị tồn (AVCO + lô dự án) so với TK 156', $avco + $lot, $glTotal, [
            ['label' => 'Khoản (1) AVCO − sổ kho', 'kind' => 'ref', 'count' => null, 'amount' => $c1['diff']],
            ['label' => 'Khoản (2) sổ kho − TK 156', 'kind' => 'ref', 'count' => null, 'amount' => $c2['diff']],
        ], $c1['unexplained'] + $c2['unexplained']);

        return [
            'thresholds' => ['qty' => IntegrityRuleCatalog::EPS_QTY, 'value' => $eps],
            'totals' => ['avco' => round($avco, 2), 'lot' => round($lot, 2), 'book' => round($book, 2), 'book_general' => round($book - $lot, 2),
                'gl156' => $gl156->all(), 'gl156_total' => round($glTotal, 2)] + $this->wipTotals(),
            'comparisons' => [$c1, $c2, $c3, $this->wipComparison()],
        ];
    }

    private function comparison(string $key, string $label, float $left, float $right, array $items, ?float $unexplained = null): array
    {
        $diff = round($left - $right, 2);
        $unexplained ??= round(collect($items)->where('kind', 'unexplained')->sum('amount'), 2);
        $status = match (true) {
            $diff == 0.0 && collect($items)->every(fn ($i) => abs($i['amount']) < 0.005) => 'zero',
            abs($unexplained) > IntegrityRuleCatalog::EPS_VND => 'unexplained',
            abs($diff) <= IntegrityRuleCatalog::EPS_VND && collect($items)->whereIn('kind', ['issue'])->isEmpty() => 'below_threshold',
            default => 'explained',
        };
        return compact('key', 'label', 'diff', 'items', 'status') + ['left' => round($left, 2), 'right' => round($right, 2), 'unexplained' => round($unexplained, 2)];
    }

    /** Chênh AVCO − kỳ vọng theo từng dòng SP×kho, gắn vào quy tắc đã lập vấn đề. Σ khoản = chênh (1). */
    private function avcoItems(Collection $rows): array
    {
        $acc = [];
        foreach ($rows->whereNotNull('warehouse_id') as $r) {
            $d = round((float) ($r->avco_value ?? 0) - (float) $r->expected_avco_value, 2);
            if (abs($d) < 0.005) {
                continue;
            }
            $st = array_column($r->row_findings, 0);
            $rule = match (true) {
                in_array('NEGATIVE_STOCK', $st, true) => 'NEGATIVE_STOCK',
                in_array('ACCOUNTING_MISMATCH', $st, true) => 'ZERO_QTY_WITH_VALUE',
                in_array('VALUE_MISMATCH', $st, true) => 'VALUE_MISMATCH',
                in_array('EXPECTED_DIFFERENCE', $st, true) => 'EXPECTED_DIFFERENCE',
                in_array('MISSING_BALANCE', $st, true) => 'MISSING_BALANCE',
                default => null,
            };
            $k = $rule ?? 'UNEXPLAINED';
            $acc[$k] ??= ['label' => self::RULE_LABELS[$rule] ?? 'Chênh chưa gắn quy tắc (vd. chưa có AVCO nhưng sổ kho còn giá trị)', 'rule' => $rule,
                'kind' => $rule === 'EXPECTED_DIFFERENCE' ? 'rounding' : ($rule ? 'issue' : 'unexplained'), 'count' => 0, 'amount' => 0.0];
            $acc[$k]['count']++;
            $acc[$k]['amount'] = round($acc[$k]['amount'] + $d, 2);
        }
        return array_values($acc);
    }

    /** Sổ kho − TK156 = Σ(movement − JE liên kết) theo chứng từ − JE 156 không liên kết chứng từ kho. */
    private function glItems(array $docFindings): array
    {
        $flagged = [];
        $manualCodes = [];
        foreach ($docFindings as $f) {
            if (in_array($f['rule'], ['MANUAL_JE_REVIEW', 'MISSING_ACCOUNTING_ENTRY', 'INVENTORY_GL_MISMATCH'], true)) {
                $flagged[$f['doc_type'] . '#' . $f['doc_id']] = $f['rule'];
                if (! empty($f['manual_je'])) {
                    $manualCodes[$f['manual_je']] = true;
                }
            }
        }
        $mv = DB::table('stock_movements')->where('status', 'active')->groupBy('source_type', 'source_id')
            ->selectRaw('source_type, source_id, SUM(amount) v')->get();
        $gl = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')->where('l.account_code', 'like', '156%')
            ->groupBy('j.reference_type', 'j.reference_id', 'j.code')->selectRaw('j.reference_type, j.reference_id, j.code, SUM(l.debit - l.credit) v')->get();

        $docs = [];
        foreach ($mv as $m) {
            $key = in_array($m->source_type, self::TYPE_LEVEL, true) ? $m->source_type . '#*' : $m->source_type . '#' . $m->source_id;
            $docs[$key] = ($docs[$key] ?? 0) + (float) $m->v;
        }
        $items = [];
        $add = function (string $k, string $label, string $kind, float $amt, ?string $rule = null) use (&$items) {
            $items[$k] ??= ['label' => $label, 'rule' => $rule, 'kind' => $kind, 'count' => 0, 'amount' => 0.0];
            $items[$k]['count']++;
            $items[$k]['amount'] = round($items[$k]['amount'] + $amt, 2);
        };
        foreach ($gl as $g) {
            $src = self::REF_TO_SOURCE[$g->reference_type ?? ''] ?? null;
            if ($src) {
                $key = in_array($src, self::TYPE_LEVEL, true) ? $src . '#*' : $src . '#' . $g->reference_id;
                $docs[$key] = ($docs[$key] ?? 0) - (float) $g->v;
            } elseif (isset($manualCodes[$g->code])) {
                $add('manual', 'JE thủ công đã đối chiếu với chứng từ (MANUAL_JE_REVIEW)', 'issue', -(float) $g->v, 'MANUAL_JE_REVIEW');
            } else {
                $add('unlinked_' . ($g->reference_type ?? 'null'), 'JE TK156 không gắn chứng từ kho (' . ($g->reference_type ?? 'thủ công') . ')', 'unexplained', -(float) $g->v);
            }
        }
        foreach ($docs as $key => $d) {
            if (abs($d) < 0.005) {
                continue;
            }
            $type = class_basename(explode('#', $key)[0]);
            if (isset($flagged[$key])) {
                $add('doc_' . $flagged[$key], "Chứng từ thiếu/lệch JE liên kết ({$flagged[$key]})", 'issue', $d, $flagged[$key]);
            } elseif (abs($d) <= IntegrityRuleCatalog::EPS_VND) {
                $add('round_' . $type, "Làm tròn theo chứng từ {$type} (≤ 10đ/chứng từ)", 'rounding', $d);
            } else {
                $add('unexp_' . $type, "Chênh chứng từ {$type} chưa gắn vấn đề", 'unexplained', $d);
            }
        }
        return array_values($items);
    }

    private function wipTotals(): array
    {
        return [
            'gl154_total' => round((float) $this->gl154()->value('v'), 2),
            'wip_active_total' => round((float) DB::table('project_wip_entries')->where('status', 'active')->sum('amount'), 2),
        ];
    }

    /** TK154 từ JE phiếu xuất ↔ WIP hiệu lực nguồn phiếu xuất. Số dư 154 tổng sau kết chuyển giá vốn là phạm vi khác — chỉ hiển thị. */
    private function wipComparison(): array
    {
        $gl = (float) $this->gl154()->where('j.reference_type', 'stock_exit')->value('v');
        $wip = (float) DB::table('project_wip_entries')->where('status', 'active')->where('source_type', 'App\Models\StockExit')->sum('amount');
        $d = round($gl - $wip, 2);
        $item = abs($d) < 0.005 ? [] : [['label' => abs($d) <= IntegrityRuleCatalog::EPS_VND ? 'Làm tròn' : 'Chênh 154 – WIP (xem PROJECT_WIP_MISMATCH)',
            'rule' => 'PROJECT_WIP_MISMATCH', 'kind' => abs($d) <= IntegrityRuleCatalog::EPS_VND ? 'rounding' : 'issue', 'count' => null, 'amount' => $d]];
        return $this->comparison('wip', '4. TK 154 ghi từ phiếu xuất so với WIP nguồn phiếu xuất (phạm vi riêng)', $gl, $wip, $item);
    }

    private function gl154()
    {
        return DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')->where('l.account_code', 'like', '154%')->selectRaw('SUM(l.debit - l.credit) v');
    }
}
