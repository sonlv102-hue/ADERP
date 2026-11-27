<?php

namespace App\Services\Inventory\Integrity;

use App\Models\InventoryIntegrityIssue as Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Truy vấn danh sách/KPI của báo cáo. Mỗi quy tắc thuộc đúng MỘT nhóm KPI và mỗi vấn đề thuộc đúng MỘT nhóm
 * phụ trách → không đếm trùng. Giá trị chỉ cộng trong cùng cơ sở đối chiếu (value_basis).
 * Người không có quyền kế toán không thấy vấn đề kế toán/chính sách (không mở rộng quyền qua báo cáo).
 */
class IntegrityReportQuery
{
    public const KPI_GROUPS = [
        'quantity' => ['QUANTITY_MISMATCH', 'MISSING_BALANCE', 'AVAILABILITY_MISMATCH', 'ALLOCATION_MISMATCH'],
        'negative' => ['NEGATIVE_STOCK'],
        'phantom' => ['PHANTOM_STOCK'],
        'value' => ['VALUE_MISMATCH'],
        'cost' => ['COST_MISMATCH'],
        'document' => ['DOCUMENT_SEQUENCE_MISMATCH', 'DOCUMENT_STATUS_MISMATCH', 'DUPLICATE_TRANSACTION', 'MISSING_DOCUMENT_LINK'],
        'missing_je' => ['MISSING_ACCOUNTING_ENTRY', 'RETURN_WITHOUT_JE'],
        'accounting' => ['INVENTORY_GL_MISMATCH', 'PROJECT_WIP_MISMATCH', 'ZERO_QTY_WITH_VALUE', 'MANUAL_JE_REVIEW', 'ACCOUNT_MAPPING_MISMATCH'],
        'expected' => ['EXPECTED_DIFFERENCE'],
        'policy' => ['POLICY_DECISION'],
    ];

    public const BASES = [
        'avco_vs_book' => 'AVCO so với sổ kho (cùng phạm vi kho chung)',
        'book_zero_qty' => 'Sổ kho/TK156 còn giá trị khi hết hàng',
        'doc_gl' => 'Movement so với JE TK156 theo chứng từ',
        'exit_cost' => 'Giá vốn dòng xuất so với movement',
        'avco_internal' => 'SL × giá BQ so với GT AVCO',
        'wip' => 'Giá vốn xuất dự án so với WIP 154',
        'gl_total' => 'Tổng TK156 so với tổng sổ kho (tổng hợp, không cộng thêm)',
    ];

    public const ACCOUNTING_ONLY_CATEGORIES = ['ACCOUNTING', 'POLICY_DECISION'];

    public function visible(User $user): Builder
    {
        return Issue::query()->when(! $user->can('accounting.view'), fn ($q) => $q->whereNotIn('category', self::ACCOUNTING_ONLY_CATEGORIES)
            ->whereNotIn('rule_code', ['RETURN_WITHOUT_JE', 'MANUAL_JE_REVIEW', 'INVENTORY_GL_MISMATCH']));
    }

    public function filtered(User $user, array $f): Builder
    {
        $q = $this->visible($user)->with(['product:id,code,name', 'warehouse:id,name', 'project:id,code', 'assignee:id,name']);
        $status = $f['status'] ?? 'open';
        $q->when($status === 'open', fn ($x) => $x->whereNotIn('status', Issue::CLOSED))
            ->when($status !== 'open' && $status !== 'all', fn ($x) => $x->where('status', $status));
        foreach (['rule_code', 'category', 'severity', 'warehouse_id', 'project_id', 'assignee_id'] as $col) {
            $q->when(($f[$col] ?? '') !== '' && $f[$col] !== null, fn ($x) => $x->where($col, $f[$col]));
        }
        $q->when(! empty($f['group']) && isset(self::KPI_GROUPS[$f['group']]), fn ($x) => $x->whereIn('rule_code', self::KPI_GROUPS[$f['group']]))
            ->when(! empty($f['bucket']), fn ($x) => $this->bucketScope($x, $f['bucket']))
            ->when(! empty($f['product_id']), fn ($x) => $x->where(fn ($y) => $y->where('product_id', $f['product_id'])
                ->orWhereRaw('CAST(evidence AS TEXT) LIKE ?', ['%"' . (int) $f['product_id'] . '-%'])))
            ->when(! empty($f['document']), fn ($x) => $x->where(fn ($y) => $y->where('document_ref', $f['document'])
                ->orWhere('document_codes', 'like', '%' . $f['document'] . '%')))
            ->when(! empty($f['search']), function ($x) use ($f) {
                $s = '%' . mb_strtolower($f['search']) . '%';
                $x->where(fn ($y) => $y->whereRaw('LOWER(cause) LIKE ?', [$s])->orWhereRaw('LOWER(COALESCE(document_codes, \'\')) LIKE ?', [$s])
                    ->orWhereHas('product', fn ($p) => $p->whereRaw('LOWER(code) LIKE ?', [$s])->orWhereRaw('LOWER(name) LIKE ?', [$s])));
            });
        $sort = in_array($f['sort'] ?? '', ['id', 'rule_code', 'severity', 'status', 'qty_diff', 'value_diff', 'last_checked_at', 'detected_at'], true) ? $f['sort'] : 'id';
        return $q->orderBy($sort, ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('id');
    }

    /** Nhóm phụ trách duy nhất của một vấn đề. */
    public static function bucket(Issue $i): string
    {
        return match (true) {
            $i->status === 'RESOLVED' => 'resolved',
            $i->status === 'ACCEPTED_DIFFERENCE' || $i->category === 'EXPECTED' => 'accepted',
            $i->status === 'PENDING_POLICY' || $i->category === 'POLICY_DECISION' => 'policy',
            $i->status === 'PENDING_TECHNICAL' => 'dev',
            $i->status === 'PENDING_ACCOUNTING' => 'accounting',
            in_array($i->status, ['PENDING_EVIDENCE', 'PENDING_VERIFICATION'], true) => 'verification',
            $i->category === 'TECHNICAL' => 'dev',
            $i->category === 'ACCOUNTING' => 'accounting',
            default => 'verification',
        };
    }

    private function bucketScope(Builder $q, string $bucket): void
    {
        $ids = (clone $q)->get(['id', 'status', 'category'])->filter(fn ($i) => self::bucket($i) === $bucket)->pluck('id');
        $q->whereIn('id', $ids->all() ?: [0]);
    }

    public function kpis(User $user): array
    {
        $all = $this->visible($user)->get(['id', 'rule_code', 'category', 'status', 'product_id', 'evidence', 'value_basis', 'value_diff', 'source', 'is_legacy']);
        $open = $all->filter(fn ($i) => $i->isOpen());
        $real = $open->reject(fn ($i) => in_array($i->category, ['EXPECTED', 'POLICY_DECISION'], true));
        $products = $real->flatMap(fn ($i) => $i->product_id ? [$i->product_id] : ($i->evidence['product_ids'] ?? []))->unique();
        $groups = [];
        foreach (self::KPI_GROUPS as $g => $rules) {
            $groups[$g] = $open->whereIn('rule_code', $rules)->count();
        }
        $buckets = array_fill_keys(['dev', 'accounting', 'verification', 'policy', 'resolved', 'accepted'], 0);
        foreach ($all as $i) {
            $buckets[self::bucket($i)]++;
        }
        $values = [];
        foreach (self::BASES as $basis => $label) {
            $rows = $open->where('value_basis', $basis)->reject(fn ($i) => $i->category === 'EXPECTED');
            if ($rows->isNotEmpty()) {
                $d = $rows->pluck('value_diff')->map(fn ($v) => (float) $v);
                $values[] = ['basis' => $basis, 'label' => $label, 'count' => $rows->count(), 'increase' => round($d->filter(fn ($v) => $v > 0)->sum(), 2),
                    'decrease' => round($d->filter(fn ($v) => $v < 0)->sum(), 2), 'net' => round($d->sum(), 2)];
            }
        }
        return ['products_with_issues' => $products->count(), 'open_issues' => $open->count(), 'groups' => $groups,
            'buckets' => $buckets, 'values' => $values, 'legacy_open' => $open->where('is_legacy', true)->count()];
    }
}
