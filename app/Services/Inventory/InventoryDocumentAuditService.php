<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra theo chứng từ kho (READ-ONLY): movement trùng/thiếu, chứng từ đã xóa còn movement,
 * JE TK 156 lệch giá trị movement, lô/allocation không khớp. Kết quả gom theo khóa "product-warehouse".
 */
class InventoryDocumentAuditService
{
    private const TYPES = [
        'App\Models\StockEntry' => ['stock_entries', 'stock_entry'],
        'App\Models\StockExit' => ['stock_exits', 'stock_exit'],
        'App\Models\StockTransfer' => ['stock_transfers', null],
        'App\Models\SalesReturn' => ['sales_returns', null],
        'App\Models\PurchaseReturn' => ['purchase_returns', null],
        'App\Models\InventoryCount' => ['inventory_counts', null],
    ];

    private ?array $docRowsCache = null;

    /** @return array<string, list<array{0:string,1:string}>> */
    public function issuesByRow(): array
    {
        $out = [];
        $add = function (string $key, string $status, string $msg) use (&$out) {
            $out[$key][] = [$status, $msg];
        };

        // 1. Số lượng movement net theo (chứng từ, sản phẩm, kho) so với dòng hàng của chứng từ
        foreach ($this->movementNetByDoc() as $m) {
            $doc = $this->doc($m->source_type, (int) $m->source_id);
            $key = $m->product_id . '-' . $m->warehouse_id;
            $label = $doc['code'] ?? (class_basename($m->source_type) . '#' . $m->source_id . ' (đã xóa)');
            $expected = $this->expectedQty($m->source_type, $doc, (int) $m->product_id, (int) $m->warehouse_id);
            if ($expected === null) {
                continue; // loại chứng từ không có dòng hàng chuẩn để so (tồn đầu kỳ, kiểm kê, chuyển kho)
            }
            $net = round((float) $m->net, 3);
            if (abs($net - $expected) > 0.0005) {
                $status = abs($net) > abs($expected) && $expected != 0 ? 'DUPLICATE_TRANSACTION' : 'QUANTITY_MISMATCH';
                $add($key, $status, "{$label} [" . ($doc['status'] ?? 'deleted') . "]: movement net {$net} ≠ chứng từ {$expected}");
            }
        }

        // 2. JE TK 156x theo chứng từ so với Σ amount movement active
        foreach ($this->jeVsMovement() as $j) {
            $diff = round((float) $j->mv_amount - (float) $j->gl_amount, 2);
            if (abs($diff) > 10) {
                $manual = $this->unlinkedManualJournal($j->code);
                $msg = $manual
                    ? "{$j->code}: không có JE tự động liên kết; đã hạch toán bằng JE thủ công {$manual->code} (Nợ 156 {$manual->debit_156}) — số tiền TK156 có, thiếu liên kết chứng từ"
                    : "{$j->code}: movement {$j->mv_amount} ≠ JE TK156 {$j->gl_amount} (lệch {$diff})";
                foreach ($this->productRowsOf($j->source_type, (int) $j->source_id) as $key) {
                    $add($key, 'ACCOUNTING_MISMATCH', $msg);
                }
            }
        }

        // 3. Lô dự án trùng cho cùng một dòng phiếu nhập
        $dupLots = DB::select("SELECT stock_entry_item_id, product_id, warehouse_id, COUNT(*) n FROM project_inventory_lots
            WHERE status <> 'cancelled' AND stock_entry_item_id IS NOT NULL GROUP BY stock_entry_item_id, product_id, warehouse_id HAVING COUNT(*) > 1");
        foreach ($dupLots as $l) {
            $add($l->product_id . '-' . $l->warehouse_id, 'DUPLICATE_TRANSACTION', "Dòng phiếu nhập #{$l->stock_entry_item_id} có {$l->n} lô dự án hiệu lực");
        }

        // 4. Allocation hiệu lực thuộc phiếu xuất không còn ở trạng thái confirmed
        $badAlloc = DB::select("SELECT a.product_id, a.warehouse_id, a.stock_exit_id, x.code, x.status FROM stock_exit_item_lot_allocations a
            LEFT JOIN stock_exits x ON x.id = a.stock_exit_id WHERE a.voided_at IS NULL AND (x.id IS NULL OR x.status <> 'confirmed')");
        foreach ($badAlloc as $a) {
            $add($a->product_id . '-' . $a->warehouse_id, 'ALLOCATION_MISMATCH',
                'Allocation hiệu lực của ' . ($a->code ?? "XK#{$a->stock_exit_id} (đã xóa)") . ' [' . ($a->status ?? 'deleted') . ']');
        }

        return $out;
    }

    /** @return array<string, list<string>> mã chứng từ liên quan theo dòng */
    public function documentCodesByRow(): array
    {
        $out = [];
        $rows = DB::select('SELECT DISTINCT product_id, warehouse_id, source_type, source_id FROM stock_movements ORDER BY source_id');
        foreach ($rows as $r) {
            $doc = $this->doc($r->source_type, (int) $r->source_id);
            $out[$r->product_id . '-' . $r->warehouse_id][] = $doc['code'] ?? (class_basename($r->source_type) . '#' . $r->source_id . '(xóa)');
        }
        return array_map(fn ($c) => array_values(array_unique($c)), $out);
    }

    /** Dòng có phiếu xuất đã xác nhận ghi theo cơ chế cũ (legacy hoặc thiếu avco_qty trước migration 900251). */
    public function legacyExitRows(): array
    {
        $rows = DB::select("SELECT DISTINCT i.product_id, x.warehouse_id FROM stock_exit_items i JOIN stock_exits x ON x.id = i.stock_exit_id
            WHERE x.status = 'confirmed' AND (i.cost_source = 'legacy' OR i.cost_source IS NULL)");
        return collect($rows)->mapWithKeys(fn ($r) => [$r->product_id . '-' . $r->warehouse_id => true])->all();
    }

    public function rowsForDocument(string $code): array
    {
        $keys = [];
        foreach (self::TYPES as $type => [$table]) {
            $id = DB::table($table)->where('code', $code)->value('id');
            if ($id) {
                foreach ($this->productRowsOf($type, (int) $id) as $k) {
                    $keys[$k] = true;
                }
            }
        }
        return $keys;
    }

    public function rowsForProject(int $projectId): array
    {
        $rows = DB::select('SELECT product_id, warehouse_id FROM stock_movements WHERE project_id = ?
            UNION SELECT product_id, warehouse_id FROM project_inventory_lots WHERE project_id = ?
            UNION SELECT i.product_id, x.warehouse_id FROM stock_exit_items i JOIN stock_exits x ON x.id = i.stock_exit_id WHERE x.project_id = ?',
            [$projectId, $projectId, $projectId]);
        return collect($rows)->mapWithKeys(fn ($r) => [$r->product_id . '-' . $r->warehouse_id => true])->all();
    }

    private function productRowsOf(string $type, int $id): array
    {
        return collect(DB::select('SELECT DISTINCT product_id, warehouse_id FROM stock_movements WHERE source_type = ? AND source_id = ?', [$type, $id]))
            ->map(fn ($r) => $r->product_id . '-' . $r->warehouse_id)->all();
    }

    private function movementNetByDoc(): array
    {
        return DB::select("SELECT source_type, source_id, product_id, warehouse_id,
                SUM(CASE WHEN status = 'active' THEN quantity ELSE 0 END) net
            FROM stock_movements GROUP BY source_type, source_id, product_id, warehouse_id");
    }

    /** Số lượng kỳ vọng của movement active theo trạng thái chứng từ; null = không so. */
    private function expectedQty(string $type, ?array $doc, int $productId, int $warehouseId): ?float
    {
        if (! isset(self::TYPES[$type]) || ! in_array($type, ['App\Models\StockEntry', 'App\Models\StockExit'], true)) {
            return null;
        }
        if ($doc === null || $doc['status'] !== 'confirmed') {
            return 0.0; // nháp / đã hủy / đã xóa → không được còn tác động tồn
        }
        if ($type === 'App\Models\StockEntry') {
            return (float) DB::table('stock_entry_items')->where('stock_entry_id', $doc['id'])->where('product_id', $productId)->sum('quantity');
        }
        // Phiếu xuất: −SL tại kho xuất; project_transfer: +SL tại kho đích
        $qty = (float) DB::table('stock_exit_items')->where('stock_exit_id', $doc['id'])->where('product_id', $productId)->sum('quantity');
        if ((int) $doc['warehouse_id'] === $warehouseId) {
            return -$qty;
        }
        return (int) ($doc['to_warehouse_id'] ?? 0) === $warehouseId ? $qty : 0.0;
    }

    private function jeVsMovement(): array
    {
        $sql = [];
        foreach (['App\Models\StockEntry' => ['stock_entries', 'stock_entry'], 'App\Models\StockExit' => ['stock_exits', 'stock_exit']] as $type => [$table, $ref]) {
            $sql[] = "SELECT '{$type}' source_type, d.id source_id, d.code,
                COALESCE((SELECT SUM(amount) FROM stock_movements m WHERE m.source_type = '{$type}' AND m.source_id = d.id AND m.status = 'active'), 0) mv_amount,
                COALESCE((SELECT SUM(l.debit - l.credit) FROM journal_entry_lines l JOIN journal_entries j ON j.id = l.journal_entry_id
                    WHERE j.reference_type = '{$ref}' AND j.reference_id = d.id AND j.status = 'posted' AND l.account_code LIKE '156%'), 0) gl_amount
                FROM {$table} d";
        }
        return DB::select(implode(' UNION ALL ', $sql));
    }

    private function unlinkedManualJournal(string $code): ?object
    {
        return DB::table('journal_entries as j')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->whereNull('j.reference_type')->where('j.status', 'posted')->where('j.description', 'like', "%{$code}%")
            ->where('l.account_code', 'like', '156%')
            ->groupBy('j.id', 'j.code')->selectRaw('j.code, SUM(l.debit - l.credit) debit_156')->first();
    }

    private function doc(string $type, int $id): ?array
    {
        if ($this->docRowsCache === null) {
            $this->docRowsCache = [];
            foreach (self::TYPES as $t => [$table]) {
                $cols = ['id', 'code', 'status'] + ($table === 'stock_exits' ? [3 => 'warehouse_id', 4 => 'to_warehouse_id'] : []);
                foreach (DB::table($table)->get($cols) as $d) {
                    $this->docRowsCache[$t][$d->id] = (array) $d;
                }
            }
        }
        $doc = $this->docRowsCache[$type][$id] ?? null;
        if ($doc && isset($doc['status']) && is_object($doc['status'])) {
            $doc['status'] = $doc['status']->value;
        }
        return $doc;
    }
}
