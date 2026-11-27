<?php

namespace App\Services\Inventory\Integrity;

use App\Services\Inventory\InventoryDocumentAuditService;
use Illuminate\Support\Facades\DB;

/**
 * Quy tắc bổ sung theo chứng từ (READ-ONLY), cùng định dạng finding với InventoryDocumentAuditService::findings().
 * Không hardcode SKU/chứng từ/số tiền: mọi phát hiện suy ra từ dữ liệu hiện tại.
 */
class IntegrityExtraRules
{
    private const EXIT = 'App\Models\StockExit';
    private const ENTRY = 'App\Models\StockEntry';

    public function __construct(private InventoryDocumentAuditService $docs) {}

    public function findings(): array
    {
        return array_merge($this->exitCost(), $this->balanceCost(), $this->projectWip(), $this->accountMapping(),
            $this->sequence(), $this->missingLinks(), $this->returnsWithoutJe(), $this->glTotal());
    }

    /** COST_MISMATCH: giá vốn dòng xuất ≠ giá trị movement ra khỏi kho xuất. */
    private function exitCost(): array
    {
        $rows = DB::select("SELECT x.id, x.code, x.warehouse_id, i.product_id, SUM(i.total_cost) cost,
                MAX(CASE WHEN i.cost_source = 'legacy' OR i.cost_source IS NULL THEN 1 ELSE 0 END) legacy,
                (SELECT -COALESCE(SUM(m.amount),0) FROM stock_movements m WHERE m.source_type = ? AND m.source_id = x.id
                    AND m.product_id = i.product_id AND m.warehouse_id = x.warehouse_id AND m.status = 'active') mv
            FROM stock_exits x JOIN stock_exit_items i ON i.stock_exit_id = x.id
            WHERE x.status = 'confirmed' GROUP BY x.id, x.code, x.warehouse_id, i.product_id", [self::EXIT]);
        $out = [];
        foreach ($rows as $r) {
            $diff = round((float) $r->mv - (float) $r->cost, 2);
            if (abs($diff) > IntegrityRuleCatalog::EPS_VND) {
                $out[] = $this->f('COST_MISMATCH', self::EXIT, $r->id, $r->code, 'confirmed', [$r->product_id . '-' . $r->warehouse_id],
                    "{$r->code}: giá vốn dòng xuất {$r->cost} ≠ giá trị movement {$r->mv}", [
                        'product_id' => (int) $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'value_book' => round((float) $r->mv, 2),
                        'value_compare' => round((float) $r->cost, 2), 'value_diff' => $diff, 'basis' => 'exit_cost', 'is_legacy' => (bool) $r->legacy]);
            }
        }
        return $out;
    }

    /** COST_MISMATCH: SL × giá bình quân ≠ giá trị AVCO (dòng balance nội bộ không nhất quán). */
    private function balanceCost(): array
    {
        $out = [];
        foreach (DB::select('SELECT product_id, warehouse_id, qty_on_hand q, value_on_hand v, avg_cost a FROM inventory_balances WHERE qty_on_hand > 0') as $b) {
            $diff = round((float) $b->v - (float) $b->q * (float) $b->a, 2);
            if (abs($diff) > IntegrityRuleCatalog::EPS_VND) {
                $out[] = $this->f('COST_MISMATCH', 'inventory_balance', 0, null, null, [$b->product_id . '-' . $b->warehouse_id],
                    "AVCO: SL {$b->q} × giá BQ {$b->a} ≠ giá trị {$b->v}", ['product_id' => (int) $b->product_id, 'warehouse_id' => (int) $b->warehouse_id,
                        'ref' => 'bal', 'value_book' => round((float) $b->v, 2), 'value_compare' => round((float) $b->q * (float) $b->a, 2), 'value_diff' => $diff, 'basis' => 'avco_internal']);
            }
        }
        return $out;
    }

    /** PROJECT_WIP_MISMATCH: phiếu xuất project_cost — giá vốn ≠ WIP TK154 hiệu lực. */
    private function projectWip(): array
    {
        $rows = DB::select("SELECT x.id, x.code, x.status, x.project_id, x.warehouse_id,
                CASE WHEN x.status = 'confirmed' THEN COALESCE((SELECT SUM(total_cost) FROM stock_exit_items i WHERE i.stock_exit_id = x.id),0) ELSE 0 END cost,
                COALESCE((SELECT SUM(w.amount) FROM project_wip_entries w WHERE w.source_type = ? AND w.source_id = x.id AND w.status = 'active'),0) wip
            FROM stock_exits x WHERE x.issue_purpose = 'project_cost'", [self::EXIT]);
        $out = [];
        foreach ($rows as $r) {
            $diff = round((float) $r->cost - (float) $r->wip, 2);
            if (abs($diff) > IntegrityRuleCatalog::EPS_VND) {
                $out[] = $this->f('PROJECT_WIP_MISMATCH', self::EXIT, $r->id, $r->code, $r->status, $this->docs->productRowsOf(self::EXIT, (int) $r->id),
                    "{$r->code} [{$r->status}]: giá vốn xuất dự án {$r->cost} ≠ WIP 154 hiệu lực {$r->wip}", [
                        'warehouse_id' => (int) $r->warehouse_id, 'project_id' => $r->project_id, 'value_book' => round((float) $r->cost, 2),
                        'value_compare' => round((float) $r->wip, 2), 'value_diff' => $diff, 'basis' => 'wip']);
            }
        }
        return $out;
    }

    /** ACCOUNT_MAPPING_MISMATCH: TK Nợ của JE phiếu xuất không khớp mục đích xuất. */
    private function accountMapping(): array
    {
        $rows = DB::select("SELECT x.id, x.code, x.issue_purpose, x.project_id, x.warehouse_id,
                SUM(CASE WHEN l.account_code LIKE '154%' THEN l.debit ELSE 0 END) d154,
                SUM(CASE WHEN l.account_code LIKE '632%' THEN l.debit ELSE 0 END) d632
            FROM stock_exits x JOIN journal_entries j ON j.reference_type = 'stock_exit' AND j.reference_id = x.id AND j.status = 'posted'
            JOIN journal_entry_lines l ON l.journal_entry_id = j.id
            WHERE x.status = 'confirmed' GROUP BY x.id, x.code, x.issue_purpose, x.project_id, x.warehouse_id");
        $out = [];
        foreach ($rows as $r) {
            $bad = ($r->issue_purpose === 'project_cost' && (float) $r->d154 <= 0)
                || ($r->issue_purpose === 'sale_delivery' && (float) $r->d154 > 0);
            if ($bad) {
                $out[] = $this->f('ACCOUNT_MAPPING_MISMATCH', self::EXIT, $r->id, $r->code, 'confirmed', $this->docs->productRowsOf(self::EXIT, (int) $r->id),
                    "{$r->code} ({$r->issue_purpose}): JE Nợ 154 = {$r->d154}, Nợ 632 = {$r->d632}", ['warehouse_id' => (int) $r->warehouse_id, 'project_id' => $r->project_id]);
            }
        }
        return $out;
    }

    /** DOCUMENT_SEQUENCE_MISMATCH: tồn lũy kế theo thời điểm ghi sổ có lúc âm nhưng tồn cuối ≥ 0. */
    private function sequence(): array
    {
        $rows = DB::select("SELECT product_id, warehouse_id, MIN(run) min_run, MAX(fin) fin FROM (
                SELECT product_id, warehouse_id,
                    SUM(quantity) OVER (PARTITION BY product_id, warehouse_id ORDER BY created_at, id) run,
                    SUM(quantity) OVER (PARTITION BY product_id, warehouse_id) fin
                FROM stock_movements WHERE status = 'active') t
            GROUP BY product_id, warehouse_id HAVING MIN(run) < -0.0005 AND MAX(fin) >= -0.0005");
        return array_map(fn ($r) => $this->f('DOCUMENT_SEQUENCE_MISMATCH', 'row', 0, null, null, [$r->product_id . '-' . $r->warehouse_id],
            'Tồn lũy kế theo thời điểm ghi sổ xuống tới ' . round((float) $r->min_run, 3) . ' trước khi có phiếu nhập (tồn cuối ' . round((float) $r->fin, 3) . ')',
            ['product_id' => (int) $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'ref' => 'seq', 'qty_book' => round((float) $r->fin, 3)]), $rows);
    }

    /** MISSING_DOCUMENT_LINK: movement trỏ tới dòng hàng đã mất, không có snapshot (V4 trở đi luôn có snapshot). */
    private function missingLinks(): array
    {
        $snap = [];
        foreach (DB::table('activity_log')->where('log_name', 'stock_entry_item')->pluck('properties') as $p) {
            $id = json_decode((string) $p, true)['stock_entry_item_id'] ?? null;
            if ($id) {
                $snap[(int) $id] = true;
            }
        }
        // Chỉ chứng từ CÒN tồn tại: chứng từ đã xóa có movement void là xóa có chủ đích (admin), movement active
        // của chứng từ đã xóa đã thuộc DOCUMENT_STATUS_MISMATCH.
        $rows = DB::select("SELECT m.source_type, m.source_id, m.product_id, m.warehouse_id, m.source_item_id, COUNT(*) n FROM stock_movements m
            WHERE m.source_item_id IS NOT NULL AND (
                (m.source_type = ? AND EXISTS (SELECT 1 FROM stock_entries d WHERE d.id = m.source_id)
                    AND NOT EXISTS (SELECT 1 FROM stock_entry_items i WHERE i.id = m.source_item_id)) OR
                (m.source_type = ? AND EXISTS (SELECT 1 FROM stock_exits d WHERE d.id = m.source_id)
                    AND NOT EXISTS (SELECT 1 FROM stock_exit_items i WHERE i.id = m.source_item_id)))
            GROUP BY m.source_type, m.source_id, m.product_id, m.warehouse_id, m.source_item_id", [self::ENTRY, self::EXIT]);
        $groups = [];
        foreach ($rows as $r) {
            if ($r->source_type === self::ENTRY && isset($snap[(int) $r->source_item_id])) {
                continue; // đã có snapshot hợp lệ → truy vết được, không cảnh báo
            }
            $g = &$groups[$r->source_type . '|' . $r->source_id . '|' . $r->product_id . '|' . $r->warehouse_id];
            $g = ($g ?? ['r' => $r, 'n' => 0, 'items' => []]);
            $g['n'] += (int) $r->n;
            $g['items'][] = '#' . $r->source_item_id;
            unset($g);
        }
        $out = [];
        foreach ($groups as $g) {
            $r = $g['r'];
            $doc = $this->docs->doc($r->source_type, (int) $r->source_id);
            $code = $doc['code'] ?? class_basename($r->source_type) . '#' . $r->source_id;
            $out[] = $this->f('MISSING_DOCUMENT_LINK', $r->source_type, (int) $r->source_id, $code, $doc['status'] ?? null, [$r->product_id . '-' . $r->warehouse_id],
                "{$code}: {$g['n']} movement trỏ tới dòng hàng " . implode(', ', $g['items']) . ' không còn tồn tại, không có snapshot (sửa phiếu trước V4) — vẫn truy vết được theo chứng từ/sản phẩm',
                ['product_id' => (int) $r->product_id, 'warehouse_id' => (int) $r->warehouse_id, 'is_legacy' => true]);
        }
        return $out;
    }

    /** RETURN_WITHOUT_JE: phiếu trả hàng đã xác nhận chưa có bút toán (chính sách A4 chưa duyệt). */
    private function returnsWithoutJe(): array
    {
        $out = [];
        foreach (['sales_returns' => ['App\Models\SalesReturn', 'sales_return'], 'purchase_returns' => ['App\Models\PurchaseReturn', 'purchase_return']] as $table => [$type, $ref]) {
            $rows = DB::select("SELECT d.id, d.code, d.warehouse_id FROM {$table} d WHERE d.status = 'confirmed'
                AND NOT EXISTS (SELECT 1 FROM journal_entries j WHERE j.reference_id = d.id AND j.reference_type IN (?, ?) AND j.status <> 'voided')", [$type, $ref]);
            foreach ($rows as $r) {
                $out[] = $this->f('RETURN_WITHOUT_JE', $type, (int) $r->id, $r->code, 'confirmed', $this->docs->productRowsOf($type, (int) $r->id),
                    "{$r->code}: đã ghi tồn kho nhưng chưa có bút toán — chờ chính sách hạch toán trả hàng (A4)", ['warehouse_id' => $r->warehouse_id]);
            }
        }
        return $out;
    }

    /** INVENTORY_GL_MISMATCH tổng: số dư TK 156x (JE posted) ≠ Σ giá trị movement hiệu lực. Không cộng với các chênh khác. */
    private function glTotal(): array
    {
        $gl = (float) DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.status', 'posted')->where('l.account_code', 'like', '156%')->sum(DB::raw('l.debit - l.credit'));
        $mv = (float) DB::table('stock_movements')->where('status', 'active')->sum('amount');
        $diff = round($mv - $gl, 2);
        if (abs($diff) <= IntegrityRuleCatalog::EPS_VND) {
            return [];
        }
        return [$this->f('INVENTORY_GL_MISMATCH', 'company', 0, 'TK156', null, [],
            "Toàn công ty: Σ giá trị sổ kho {$mv} ≠ số dư TK 156 {$gl} (lệch {$diff}). Chênh này là tổng hợp, đã bao gồm các chênh theo chứng từ/sản phẩm — không cộng thêm.",
            ['ref' => 'total', 'value_book' => round($mv, 2), 'value_compare' => round($gl, 2), 'value_diff' => $diff, 'basis' => 'gl_total'])];
    }

    private function f(string $rule, string $type, int $id, ?string $code, ?string $status, array $keys, string $msg, array $extra = []): array
    {
        return $extra + ['rule' => $rule, 'legacy' => $rule, 'keys' => array_values(array_unique($keys)), 'doc_type' => $type,
            'doc_id' => $id, 'doc_code' => $code, 'doc_status' => $status, 'msg' => $msg];
    }
}
