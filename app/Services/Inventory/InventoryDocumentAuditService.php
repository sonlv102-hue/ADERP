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
        foreach ($this->findings() as $f) {
            foreach ($f['keys'] as $key) {
                $out[$key][] = [$f['legacy'], $f['msg']];
            }
        }
        return $out;
    }

    /**
     * Phát hiện theo chứng từ, có cấu trúc (dùng cho báo cáo kiểm soát sai lệch).
     * 'rule' = mã quy tắc báo cáo; 'legacy' = trạng thái cũ của `inventory:audit --scope=full` (giữ nguyên CLI).
     * @return list<array<string, mixed>>
     */
    public function findings(): array
    {
        $out = [];

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
                $legacy = abs($net) > abs($expected) && $expected != 0 ? 'DUPLICATE_TRANSACTION' : 'QUANTITY_MISMATCH';
                $status = $doc['status'] ?? 'deleted';
                $out[] = $this->finding($status !== 'confirmed' ? 'DOCUMENT_STATUS_MISMATCH' : $legacy, $legacy, [$key], $m->source_type, (int) $m->source_id, $label, $status,
                    "{$label} [{$status}]: movement net {$net} ≠ chứng từ {$expected}",
                    ['product_id' => (int) $m->product_id, 'warehouse_id' => (int) $m->warehouse_id, 'qty_book' => $net, 'qty_expected' => $expected]);
            }
        }

        // 1b. Chiều ngược: dòng chứng từ đã xác nhận nhưng KHÔNG có movement nào tại (sản phẩm, kho) kỳ vọng
        $seen = [];
        foreach ($this->movementNetByDoc() as $m) {
            $seen[$m->source_type . '#' . $m->source_id . '#' . $m->product_id . '#' . $m->warehouse_id] = true;
        }
        foreach ($this->expectedPairs() as $e) {
            if (isset($seen[$e->t . '#' . $e->id . '#' . $e->p . '#' . $e->w])) {
                continue;
            }
            $doc = $this->doc($e->t, (int) $e->id);
            $expected = $this->expectedQty($e->t, $doc, (int) $e->p, (int) $e->w);
            if ($expected !== null && abs($expected) > 0.0005) {
                $out[] = $this->finding('QUANTITY_MISMATCH', 'QUANTITY_MISMATCH', [$e->p . '-' . $e->w], $e->t, (int) $e->id, $doc['code'] ?? null, 'confirmed',
                    ($doc['code'] ?? '') . " [confirmed]: thiếu movement tại kho #{$e->w} (chứng từ {$expected}, movement 0)",
                    ['product_id' => (int) $e->p, 'warehouse_id' => (int) $e->w, 'qty_book' => 0.0, 'qty_expected' => $expected]);
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
                $rule = $manual ? 'MANUAL_JE_REVIEW' : (abs((float) $j->gl_amount) < 0.005 ? 'MISSING_ACCOUNTING_ENTRY' : 'INVENTORY_GL_MISMATCH');
                $doc = $this->doc($j->source_type, (int) $j->source_id);
                $out[] = $this->finding($rule, 'ACCOUNTING_MISMATCH', $this->productRowsOf($j->source_type, (int) $j->source_id),
                    $j->source_type, (int) $j->source_id, $j->code, $doc['status'] ?? null, $msg,
                    ['value_book' => round((float) $j->mv_amount, 2), 'value_compare' => round((float) $j->gl_amount, 2), 'value_diff' => $diff,
                     'manual_je' => $manual?->code, 'manual_je_amount' => $manual ? round((float) $manual->debit_156, 2) : null]);
            }
        }

        // 3. Lô dự án trùng cho cùng một dòng phiếu nhập
        $dupLots = DB::select("SELECT l.stock_entry_item_id, l.product_id, l.warehouse_id, COUNT(*) n, MAX(i.stock_entry_id) entry_id FROM project_inventory_lots l
            LEFT JOIN stock_entry_items i ON i.id = l.stock_entry_item_id
            WHERE l.status <> 'cancelled' AND l.stock_entry_item_id IS NOT NULL GROUP BY l.stock_entry_item_id, l.product_id, l.warehouse_id HAVING COUNT(*) > 1");
        foreach ($dupLots as $l) {
            $doc = $l->entry_id ? $this->doc('App\Models\StockEntry', (int) $l->entry_id) : null;
            $out[] = $this->finding('DUPLICATE_TRANSACTION', 'DUPLICATE_TRANSACTION', [$l->product_id . '-' . $l->warehouse_id],
                'App\Models\StockEntry', (int) $l->entry_id, $doc['code'] ?? null, $doc['status'] ?? null,
                "Dòng phiếu nhập #{$l->stock_entry_item_id} có {$l->n} lô dự án hiệu lực",
                ['product_id' => (int) $l->product_id, 'warehouse_id' => (int) $l->warehouse_id, 'ref' => 'item' . $l->stock_entry_item_id]);
        }

        // 4. Allocation hiệu lực thuộc phiếu xuất không còn ở trạng thái confirmed
        $badAlloc = DB::select("SELECT a.product_id, a.warehouse_id, a.stock_exit_id, x.code, x.status FROM stock_exit_item_lot_allocations a
            LEFT JOIN stock_exits x ON x.id = a.stock_exit_id WHERE a.voided_at IS NULL AND (x.id IS NULL OR x.status <> 'confirmed')");
        foreach ($badAlloc as $a) {
            $label = $a->code ?? "XK#{$a->stock_exit_id} (đã xóa)";
            $out[] = $this->finding('ALLOCATION_MISMATCH', 'ALLOCATION_MISMATCH', [$a->product_id . '-' . $a->warehouse_id],
                'App\Models\StockExit', (int) $a->stock_exit_id, $label, $a->status ?? 'deleted',
                "Allocation hiệu lực của {$label} [" . ($a->status ?? 'deleted') . ']',
                ['product_id' => (int) $a->product_id, 'warehouse_id' => (int) $a->warehouse_id]);
        }

        return $out;
    }

    private function finding(string $rule, string $legacy, array $keys, string $type, int $id, ?string $code, ?string $status, string $msg, array $extra = []): array
    {
        return $extra + ['rule' => $rule, 'legacy' => $legacy, 'keys' => array_values(array_unique($keys)), 'doc_type' => $type,
            'doc_id' => $id, 'doc_code' => $code, 'doc_status' => $status, 'msg' => $msg];
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

    public function productRowsOf(string $type, int $id): array
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

    /** Số lượng kỳ vọng của movement active theo trạng thái chứng từ; null = không so (tồn đầu kỳ, nguồn lạ). */
    private function expectedQty(string $type, ?array $doc, int $productId, int $warehouseId): ?float
    {
        if (! isset(self::TYPES[$type])) {
            return null;
        }
        if ($doc === null || $doc['status'] !== 'confirmed') {
            return 0.0; // nháp / đã hủy / đã xóa → không được còn tác động tồn
        }
        $sum = fn (string $table, string $fk, string $col = 'quantity') => (float) DB::table($table)->where($fk, $doc['id'])
            ->where('product_id', $productId)->sum($col);
        $at = fn (?int $wid) => (int) ($wid ?? 0) === $warehouseId;
        return match ($type) {
            'App\Models\StockEntry' => $sum('stock_entry_items', 'stock_entry_id'),
            // Phiếu xuất: −SL tại kho xuất; project_transfer: +SL tại kho đích
            'App\Models\StockExit' => $at($doc['warehouse_id'] ?? null) ? -$sum('stock_exit_items', 'stock_exit_id')
                : ($at($doc['to_warehouse_id'] ?? null) ? $sum('stock_exit_items', 'stock_exit_id') : 0.0),
            'App\Models\StockTransfer' => ($at($doc['to_warehouse_id'] ?? null) ? $sum('stock_transfer_items', 'stock_transfer_id') : 0.0)
                - ($at($doc['from_warehouse_id'] ?? null) ? $sum('stock_transfer_items', 'stock_transfer_id') : 0.0),
            'App\Models\SalesReturn' => $at($doc['warehouse_id'] ?? null) ? $sum('sales_return_items', 'sales_return_id') : 0.0,
            'App\Models\PurchaseReturn' => $at($doc['warehouse_id'] ?? null) ? -$sum('purchase_return_items', 'purchase_return_id') : 0.0,
            'App\Models\InventoryCount' => $at($doc['warehouse_id'] ?? null)
                ? $sum('inventory_count_items', 'inventory_count_id', 'counted_quantity') - $sum('inventory_count_items', 'inventory_count_id', 'system_quantity') : 0.0,
            default => null,
        };
    }

    /** (loại, id, sản phẩm, kho) mà chứng từ đã xác nhận phải có movement. */
    private function expectedPairs(): array
    {
        $q = "SELECT 'App\\Models\\StockEntry' t, d.id, i.product_id p, d.warehouse_id w FROM stock_entries d JOIN stock_entry_items i ON i.stock_entry_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\StockExit', d.id, i.product_id, d.warehouse_id FROM stock_exits d JOIN stock_exit_items i ON i.stock_exit_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\StockExit', d.id, i.product_id, d.to_warehouse_id FROM stock_exits d JOIN stock_exit_items i ON i.stock_exit_id = d.id
                WHERE d.status = 'confirmed' AND d.issue_purpose = 'project_transfer' AND d.to_warehouse_id IS NOT NULL
            UNION SELECT 'App\\Models\\StockTransfer', d.id, i.product_id, d.from_warehouse_id FROM stock_transfers d JOIN stock_transfer_items i ON i.stock_transfer_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\StockTransfer', d.id, i.product_id, d.to_warehouse_id FROM stock_transfers d JOIN stock_transfer_items i ON i.stock_transfer_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\SalesReturn', d.id, i.product_id, d.warehouse_id FROM sales_returns d JOIN sales_return_items i ON i.sales_return_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\PurchaseReturn', d.id, i.product_id, d.warehouse_id FROM purchase_returns d JOIN purchase_return_items i ON i.purchase_return_id = d.id WHERE d.status = 'confirmed'
            UNION SELECT 'App\\Models\\InventoryCount', d.id, i.product_id, d.warehouse_id FROM inventory_counts d JOIN inventory_count_items i ON i.inventory_count_id = d.id
                WHERE d.status = 'confirmed' AND i.counted_quantity <> i.system_quantity";
        return DB::select($q);
    }

    private function jeVsMovement(): array
    {
        $sql = [];
        foreach (['App\Models\StockEntry' => ['stock_entries', 'stock_entry'], 'App\Models\StockExit' => ['stock_exits', 'stock_exit'],
            'App\Models\InventoryCount' => ['inventory_counts', 'App\Models\InventoryCount']] as $type => [$table, $ref]) {
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

    public function doc(string $type, int $id): ?array
    {
        if ($this->docRowsCache === null) {
            $this->docRowsCache = [];
            foreach (self::TYPES as $t => [$table]) {
                $cols = array_merge(['id', 'code', 'status'], match ($table) {
                    'stock_exits' => ['warehouse_id', 'to_warehouse_id'],
                    'stock_transfers' => ['from_warehouse_id', 'to_warehouse_id'],
                    default => ['warehouse_id'],
                });
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
