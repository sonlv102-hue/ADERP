<?php

namespace App\Services\Inventory\Integrity;

use App\Services\Inventory\InventoryDocumentAuditService;
use App\Services\Inventory\InventoryFullAuditService;

/**
 * Gom phát hiện của audit engine hiện có (InventoryFullAuditService + InventoryDocumentAuditService)
 * và IntegrityExtraRules thành danh sách vấn đề chuẩn hóa, lọc theo phạm vi. READ-ONLY.
 * Một sai lệch chỉ thuộc MỘT quy tắc/MỘT cơ sở giá trị (value_basis) → không cộng trùng.
 */
class IntegrityFindingCollector
{
    public array $stats = [];

    public function __construct(
        private InventoryFullAuditService $audit,
        private InventoryDocumentAuditService $docs,
        private IntegrityExtraRules $extra,
    ) {}

    /** @return array<string, array> issue_key => thuộc tính vấn đề */
    public function collect(IntegrityScope $scope): array
    {
        $rows = $this->audit->run($scope->auditFilters(), [], false);
        $pw = $rows->whereNotNull('warehouse_id');
        $this->stats = [
            'products_checked' => $rows->pluck('product_id')->unique()->count(),
            'warehouses_checked' => $pw->pluck('warehouse_id')->unique()->count(),
            'rows_checked' => $pw->count(),
            'legacy_rows' => $pw->filter(fn ($r) => in_array('LEGACY_DATA', array_column($r->row_findings, 0), true))->count(),
        ];

        $out = [];
        foreach ($pw as $r) {
            $statuses = array_column($r->row_findings, 0);
            $legacy = in_array('LEGACY_DATA', $statuses, true);
            foreach ($r->row_findings as [$status, $msg]) {
                if (in_array($status, ['VALID', 'LEGACY_DATA'], true)) {
                    continue;
                }
                // AVCO = 0 nhưng kỳ vọng âm: gốc là tồn âm. Không lập thêm vấn đề tồn ảo / lệch giá trị —
                // giá trị lệch được ghi trên chính vấn đề NEGATIVE_STOCK → không đếm trùng.
                if (in_array($status, ['PHANTOM_STOCK', 'VALUE_MISMATCH'], true) && $this->negativeRoot($r, $statuses)) {
                    continue;
                }
                $rule = $status === 'ACCOUNTING_MISMATCH' ? 'ZERO_QTY_WITH_VALUE' : $status;
                $issue = $this->fromRow($rule, $r, $msg, $legacy);
                $out[$issue['issue_key']] = $issue;
            }
        }

        foreach (array_merge($this->docs->findings(), $this->extra->findings()) as $f) {
            if ($scope->matchesFinding($f)) {
                $issue = $this->fromDoc($f);
                $out[$issue['issue_key']] = $issue;
            }
        }

        if ($scope->type === 'all') {
            foreach (config('inventory_integrity.policy_topics', []) as $code => $t) {
                $out["POLICY_DECISION|{$code}"] = array_merge($this->base('POLICY_DECISION', "POLICY_DECISION|{$code}"), [
                    'source' => 'policy', 'document_ref' => $code, 'cause' => $t['title'] . ': ' . $t['description'],
                    'proposal' => $t['proposal'], 'evidence' => ['level' => 'policy', 'keys' => [], 'topic' => $code, 'options' => $t['options'] ?? []],
                ]);
            }
        }
        return $out;
    }

    private function fromRow(string $rule, object $r, string $msg, bool $legacy): array
    {
        $valueRule = in_array($rule, ['VALUE_MISMATCH', 'EXPECTED_DIFFERENCE'], true)
            || ($rule === 'NEGATIVE_STOCK' && $this->negativeRoot($r, array_column($r->row_findings, 0)) && abs((float) $r->value_diff) > 0.005);
        $zero = $rule === 'ZERO_QTY_WITH_VALUE';
        $key = $r->product_id . '-' . $r->warehouse_id;
        return array_merge($this->base($rule, "{$rule}|p{$r->product_id}|w{$r->warehouse_id}"), [
            'is_legacy' => $legacy, 'product_id' => $r->product_id, 'warehouse_id' => $r->warehouse_id,
            'document_codes' => $r->documents, 'qty_book' => $r->mv_qty, 'qty_avco' => $r->avco_qty, 'qty_lot' => $r->lot_qty,
            'qty_available' => $r->available_qty,
            'qty_diff' => match ($rule) {
                'NEGATIVE_STOCK' => min($r->mv_qty, $r->expected_avco_qty),
                'MISSING_BALANCE', 'AVAILABILITY_MISMATCH' => round((float) $r->available_qty - $r->expected_avco_qty, 3),
                'ALLOCATION_MISMATCH' => round($r->lot_issued - $r->alloc_qty, 3),
                default => $r->qty_diff,
            },
            'value_book' => $r->expected_avco_value,
            'value_compare' => $zero ? 0.0 : $r->avco_value,
            'value_diff' => $zero ? $r->expected_avco_value : ($valueRule ? $r->value_diff : null),
            'value_basis' => $zero ? 'book_zero_qty' : ($valueRule ? 'avco_vs_book' : null),
            'cause' => $msg,
            'evidence' => ['level' => 'row', 'keys' => [$key], 'mv_value' => $r->mv_value, 'lot_value' => $r->lot_value,
                'in_qty' => $r->in_qty, 'out_qty' => $r->out_qty, 'lot_issued' => $r->lot_issued, 'alloc_qty' => $r->alloc_qty,
                'expected_avco_qty' => $r->expected_avco_qty, 'expected_avco_value' => $r->expected_avco_value,
                'avg_cost' => $r->avg_cost, 'initialized_from' => $r->initialized_from, 'inactive' => (bool) $r->inactive],
        ]);
    }

    private function negativeRoot(object $r, array $statuses): bool
    {
        return in_array('NEGATIVE_STOCK', $statuses, true) && $r->avco_qty !== null && abs((float) $r->avco_qty) <= IntegrityRuleCatalog::EPS_QTY;
    }

    private function fromDoc(array $f): array
    {
        $keys = $f['keys'];
        $pids = array_values(array_unique(array_map(fn ($k) => (int) explode('-', $k)[0], $keys)));
        $pid = $f['product_id'] ?? (count($pids) === 1 ? $pids[0] : null);
        $wid = $f['warehouse_id'] ?? (count($keys) === 1 ? (int) explode('-', $keys[0])[1] : null);
        $docKey = class_basename($f['doc_type']) . '#' . $f['doc_id'];
        $issueKey = "{$f['rule']}|{$docKey}|p" . ($f['product_id'] ?? '*') . '|w' . ($f['warehouse_id'] ?? '*') . '|' . ($f['ref'] ?? '');
        $basis = $f['basis'] ?? (in_array($f['rule'], ['INVENTORY_GL_MISMATCH', 'MISSING_ACCOUNTING_ENTRY', 'MANUAL_JE_REVIEW'], true) ? 'doc_gl' : null);
        return array_merge($this->base($f['rule'], $issueKey), [
            'is_legacy' => (bool) ($f['is_legacy'] ?? false), 'product_id' => $pid, 'warehouse_id' => $wid,
            'project_id' => $f['project_id'] ?? null, 'document_ref' => $f['doc_code'], 'document_codes' => $f['doc_code'],
            'qty_book' => $f['qty_book'] ?? null, 'qty_diff' => isset($f['qty_expected']) ? round($f['qty_book'] - $f['qty_expected'], 3) : null,
            'value_book' => $f['value_book'] ?? null, 'value_compare' => $f['value_compare'] ?? null, 'value_diff' => $f['value_diff'] ?? null,
            'value_basis' => $basis, 'cause' => $f['msg'],
            'evidence' => ['level' => $f['doc_type'] === 'company' ? 'aggregate' : 'doc', 'keys' => $keys, 'product_ids' => $pids,
                'doc_type' => $f['doc_type'], 'doc_id' => $f['doc_id'], 'doc_status' => $f['doc_status'],
                'manual_je' => $f['manual_je'] ?? null, 'manual_je_amount' => $f['manual_je_amount'] ?? null, 'qty_expected' => $f['qty_expected'] ?? null],
        ]);
    }

    private function base(string $rule, string $key): array
    {
        $m = IntegrityRuleCatalog::meta($rule);
        return ['issue_key' => $key, 'rule_code' => $rule, 'source' => 'auto', 'category' => $m['category'], 'severity' => $m['severity'],
            'is_legacy' => false, 'product_id' => null, 'warehouse_id' => null, 'project_id' => null, 'document_ref' => null,
            'document_codes' => null, 'qty_book' => null, 'qty_avco' => null, 'qty_lot' => null, 'qty_available' => null, 'qty_diff' => null,
            'value_book' => null, 'value_compare' => null, 'value_diff' => null, 'value_basis' => null, 'proposal' => $this->proposal($m['category'])];
    }

    private function proposal(string $category): string
    {
        return match ($category) {
            'TECHNICAL' => 'DEV truy vết; repair kỹ thuật bằng manifest khi đủ bằng chứng (dry-run trước)',
            'ACCOUNTING' => 'Kế toán đối chiếu chứng từ/JE; điều chỉnh theo phương án được duyệt',
            'BUSINESS' => 'Kiểm tra nghiệp vụ thực tế (chứng từ nhập/xuất, kiểm kê)',
            'POLICY_DECISION' => 'Chờ Owner/kế toán trưởng quyết định chính sách',
            'EXPECTED' => 'Kế toán trưởng xác nhận chênh lệch hợp lệ',
            default => 'Bổ sung bằng chứng',
        };
    }
}
