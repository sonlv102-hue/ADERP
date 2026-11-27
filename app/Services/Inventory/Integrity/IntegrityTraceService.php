<?php

namespace App\Services\Inventory\Integrity;

use App\Models\InventoryIntegrityIssue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Drill-down một vấn đề (READ-ONLY): movement gốc/đảo, dòng hàng hoặc snapshot, lô FIFO/allocation, AVCO,
 * giá vốn, JE, WIP, lịch sử chứng từ. Chỉ tạo link khi chứng từ CÒN tồn tại và người xem có quyền route đó.
 * JE/TK chỉ trả về khi người xem có quyền xem kế toán.
 */
class IntegrityTraceService
{
    private const DOCS = [
        'App\Models\StockEntry' => ['stock_entries', 'warehouse.stock-entries.show', 'warehouse.stock_entries.view', 'stock_entry'],
        'App\Models\StockExit' => ['stock_exits', 'warehouse.stock-exits.show', 'warehouse.stock_exits.view', 'stock_exit'],
        'App\Models\StockTransfer' => ['stock_transfers', 'warehouse.stock-transfers.show', 'warehouse.view', null],
        'App\Models\SalesReturn' => ['sales_returns', 'sales.sales-returns.show', 'sales-returns.view', null],
        'App\Models\PurchaseReturn' => ['purchase_returns', 'purchasing.purchase-returns.show', 'purchasing.view', null],
        'App\Models\InventoryCount' => ['inventory_counts', 'warehouse.inventory-counts.show', 'warehouse.view', null],
    ];

    private array $docCache = [];

    public function trace(InventoryIntegrityIssue $issue, User $user): array
    {
        $ev = $issue->evidence ?? [];
        $keys = $ev['keys'] ?? [];
        $pairs = array_map(fn ($k) => array_map('intval', explode('-', $k)), array_slice($keys, 0, 20));
        $canAcc = $user->can('accounting.view');

        $movements = collect();
        foreach ($pairs as [$p, $w]) {
            $movements = $movements->merge(DB::table('stock_movements')->where('product_id', $p)->where('warehouse_id', $w)
                ->when(($ev['level'] ?? '') === 'doc', fn ($q) => $q->where('source_type', $ev['doc_type'])->where('source_id', $ev['doc_id']))
                ->orderBy('created_at')->orderBy('id')->limit(500)->get());
        }
        $docIds = $movements->groupBy('source_type')->map(fn ($g) => $g->pluck('source_id')->unique()->values()->all())->all();
        if (($ev['level'] ?? '') === 'doc' && isset(self::DOCS[$ev['doc_type'] ?? ''])) {
            $docIds[$ev['doc_type']] = array_values(array_unique(array_merge($docIds[$ev['doc_type']] ?? [], [$ev['doc_id']])));
        }
        $snapshots = $this->snapshots($movements->pluck('source_item_id')->filter()->unique()->all());

        return [
            'expected' => $this->expected($issue),
            'movements' => $movements->map(fn ($m) => $this->movement($m, $user, $snapshots))->values(),
            'balances' => collect($pairs)->map(fn ($pw) => DB::table('inventory_balances')->where('product_id', $pw[0])->where('warehouse_id', $pw[1])
                ->first(['product_id', 'warehouse_id', 'qty_on_hand', 'value_on_hand', 'avg_cost', 'initialized_from']))->filter()->values(),
            'lots' => collect($pairs)->flatMap(fn ($pw) => DB::table('project_inventory_lots as l')->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
                ->where('l.product_id', $pw[0])->where('l.warehouse_id', $pw[1])
                ->get(['l.id', 'p.code as project_code', 'l.received_qty', 'l.issued_qty', 'l.unit_cost', 'l.status', 'l.stock_entry_item_id']))->values(),
            'allocations' => collect($pairs)->flatMap(fn ($pw) => DB::table('stock_exit_item_lot_allocations as a')->leftJoin('stock_exits as x', 'x.id', '=', 'a.stock_exit_id')
                ->where('a.product_id', $pw[0])->where('a.warehouse_id', $pw[1])
                ->get(['a.id', 'x.code as exit_code', 'a.project_inventory_lot_id', 'a.allocated_qty', 'a.unit_cost', 'a.voided_at']))->values(),
            'exit_costs' => DB::table('stock_exit_items as i')->join('stock_exits as x', 'x.id', '=', 'i.stock_exit_id')
                ->whereIn('x.id', $docIds['App\Models\StockExit'] ?? [0])->whereIn('i.product_id', array_column($pairs, 0) ?: [0])
                ->get(['x.code', 'x.status', 'x.issue_purpose', 'i.product_id', 'i.quantity', 'i.source_cost', 'i.total_cost', 'i.cost_source']),
            'entry_prices' => DB::table('stock_entry_items as i')->join('stock_entries as e', 'e.id', '=', 'i.stock_entry_id')
                ->whereIn('e.id', $docIds['App\Models\StockEntry'] ?? [0])->whereIn('i.product_id', array_column($pairs, 0) ?: [0])
                ->get(['i.id', 'e.code', 'e.status', 'i.product_id', 'i.quantity', 'i.unit_price']),
            'journals' => $canAcc ? $this->journals($docIds, $ev, $user) : null,
            'wip' => $canAcc ? DB::table('project_wip_entries')->where('source_type', 'App\Models\StockExit')
                ->whereIn('source_id', $docIds['App\Models\StockExit'] ?? [0])->get(['source_id', 'amount', 'status', 'entry_date']) : null,
            'document_history' => $this->history($docIds),
            'accounting_hidden' => ! $canAcc,
        ];
    }

    public function docLink(?string $type, ?int $id, User $user): ?string
    {
        if (! $type || ! $id || ! isset(self::DOCS[$type]) || ! $this->doc($type, $id)) {
            return null; // không tạo liên kết giả cho chứng từ không còn tồn tại
        }
        [, $route, $perm] = self::DOCS[$type];
        return $user->can($perm) ? route($route, $id) : null;
    }

    private function movement(object $m, User $user, array $snapshots): array
    {
        $doc = $this->doc($m->source_type, (int) $m->source_id);
        $itemExists = $m->source_item_id ? DB::table($m->source_type === 'App\Models\StockExit' ? 'stock_exit_items' : 'stock_entry_items')
            ->where('id', $m->source_item_id)->exists() : null;
        return [
            'id' => $m->id, 'created_at' => (string) $m->created_at, 'quantity' => (float) $m->quantity, 'unit_cost' => $m->unit_cost,
            'amount' => $m->amount, 'status' => $m->status, 'project_id' => $m->project_id, 'notes' => $m->notes,
            'kind' => $m->status === 'voided' ? 'voided' : (str_contains((string) $m->notes, 'Đảo') || str_contains((string) $m->notes, 'Hủy') ? 'reversal' : 'original'),
            'document' => $doc['code'] ?? class_basename($m->source_type) . '#' . $m->source_id . ' (đã xóa)',
            'document_status' => $doc['status'] ?? 'deleted', 'link' => $this->docLink($m->source_type, (int) $m->source_id, $user),
            'source_item_id' => $m->source_item_id, 'item_exists' => $itemExists,
            'snapshot' => $m->source_item_id && ! $itemExists ? ($snapshots[$m->source_item_id] ?? null) : null,
        ];
    }

    private function snapshots(array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('activity_log')->where('log_name', 'stock_entry_item')->orderBy('id')->get(['description', 'properties', 'created_at']) as $a) {
            $p = json_decode((string) $a->properties, true) ?? [];
            if (in_array($p['stock_entry_item_id'] ?? null, $itemIds)) {
                $out[$p['stock_entry_item_id']] = ['description' => $a->description, 'at' => (string) $a->created_at] + $p;
            }
        }
        return $out;
    }

    private function journals(array $docIds, array $ev, User $user): array
    {
        $q = DB::table('journal_entries as j')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->where(function ($w) use ($docIds) {
                foreach (['App\Models\StockEntry' => 'stock_entry', 'App\Models\StockExit' => 'stock_exit'] as $t => $ref) {
                    $w->orWhere(fn ($x) => $x->where('j.reference_type', $ref)->whereIn('j.reference_id', $docIds[$t] ?? [0]));
                }
            });
        if (! empty($ev['manual_je'])) {
            $q->orWhere('j.code', $ev['manual_je']);
        }
        $canLink = $user->can('accounting.journals.view');
        return $q->orderBy('j.id')->limit(300)->get(['j.id', 'j.code', 'j.entry_date', 'j.status', 'j.reference_type', 'j.reference_id', 'l.account_code', 'l.debit', 'l.credit', 'l.project_id'])
            ->map(fn ($r) => (array) $r + ['link' => $canLink ? route('accounting.journal-entries.show', $r->id) : null])->all();
    }

    private function history(array $docIds): array
    {
        $q = DB::table('activity_log')->where(function ($w) use ($docIds) {
            foreach ($docIds as $type => $ids) {
                $w->orWhere(fn ($x) => $x->where('subject_type', $type)->whereIn('subject_id', $ids));
            }
        });
        return $docIds ? $q->orderByDesc('id')->limit(100)->get(['log_name', 'description', 'subject_type', 'subject_id', 'created_at'])->all() : [];
    }

    private function expected(InventoryIntegrityIssue $i): array
    {
        $ev = $i->evidence ?? [];
        return array_filter([
            'expected_avco_qty' => $ev['expected_avco_qty'] ?? null, 'expected_avco_value' => $ev['expected_avco_value'] ?? null,
            'qty_expected' => $ev['qty_expected'] ?? null, 'formula' => IntegrityRuleCatalog::meta($i->rule_code)['formula'],
        ], fn ($v) => $v !== null);
    }

    private function doc(string $type, int $id): ?array
    {
        if (! isset(self::DOCS[$type])) {
            return null;
        }
        return $this->docCache[$type][$id] ??= ((array) (DB::table(self::DOCS[$type][0])->where('id', $id)->first(['id', 'code', 'status']) ?? [])) ?: null;
    }
}
