<?php

namespace App\Services\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit 100% tồn kho theo (sản phẩm × kho) — READ-ONLY.
 *
 * Ba lớp số liệu, không cộng trùng:
 *  - Sổ kho (book)      = Σ stock_movements ACTIVE (gồm cả hàng của lô dự án) — nguồn của báo cáo tồn/KPI và TK 156.
 *  - Lô dự án (FIFO)    = Σ (received − issued) của project_inventory_lots chưa hủy.
 *  - Kho chung (AVCO)   = inventory_balances; kỳ vọng = sổ kho − lô dự án.
 * Tồn khả dụng kho chung = InventoryBalance::availableNonProject() (cùng hàm với form xuất/search/backend).
 * Không chứng minh được tồn vật lý: chỉ kiểm kê mới xác nhận số thực tế.
 */
class InventoryFullAuditService
{
    public const STATUSES = [
        'VALID', 'EXPECTED_DIFFERENCE', 'QUANTITY_MISMATCH', 'VALUE_MISMATCH', 'AVAILABILITY_MISMATCH',
        'PHANTOM_STOCK', 'NEGATIVE_STOCK', 'MISSING_BALANCE', 'DUPLICATE_TRANSACTION', 'ALLOCATION_MISMATCH',
        'ACCOUNTING_MISMATCH', 'LEGACY_DATA', 'UNRESOLVED',
    ];

    private const QTY_EPS = 0.0005;
    private const ROUNDING_VND = 10;     // chênh ≤ 10đ: làm tròn (EXPECTED_DIFFERENCE)

    public function __construct(private InventoryDocumentAuditService $docs) {}

    /**
     * @param array{product?:int[]|null, warehouse?:int|null, project?:int|null, document?:string|null} $f
     * @param array<string, array{status?:string, note?:string}> $notes  key "pid-wid" → ghi chú/khảo sát thủ công
     */
    public function run(array $f = [], array $notes = []): Collection
    {
        $docIssues = $this->docs->issuesByRow();
        $docCodes  = $this->docs->documentCodesByRow();
        $legacyExits = $this->docs->legacyExitRows();

        $rows = collect(DB::select($this->sql()))->map(function ($r) use ($docIssues, $docCodes, $legacyExits, $notes) {
            $key = $r->product_id . '-' . ($r->warehouse_id ?? '');
            foreach (['mv_qty', 'mv_value', 'in_qty', 'out_qty', 'lot_qty', 'lot_value', 'lot_received', 'lot_issued', 'alloc_qty'] as $c) {
                $r->$c = round((float) $r->$c, 3);
            }
            $r->avco_qty   = $r->avco_qty === null ? null : (float) $r->avco_qty;
            $r->avco_value = $r->avco_value === null ? null : round((float) $r->avco_value, 2);
            $r->expected_avco_qty   = round($r->mv_qty - $r->lot_qty, 3);
            $r->expected_avco_value = round($r->mv_value - $r->lot_value, 2);
            $r->available_qty = $r->warehouse_id === null ? 0.0
                : ($r->avco_qty ?? round((float) $r->fallback_qty, 3));
            $r->qty_diff   = $r->avco_qty === null ? null : round($r->avco_qty - $r->expected_avco_qty, 3);
            $r->value_diff = $r->avco_value === null ? null : round($r->avco_value - $r->expected_avco_value, 2);
            $r->documents  = implode(' ', $docCodes[$key] ?? []);

            [$statuses, $causes] = $this->classify($r, $docIssues[$key] ?? [], isset($legacyExits[$key]));
            if (isset($notes[$key])) {
                if (! empty($notes[$key]['status'])) {
                    $statuses[] = $notes[$key]['status'];
                }
                if (! empty($notes[$key]['note'])) {
                    $causes[] = $notes[$key]['note'];
                }
            }
            $r->statuses = array_values(array_unique($statuses ?: ['VALID']));
            $r->cause    = implode(' | ', array_unique($causes));
            return $r;
        });

        return $this->filter($rows, $f);
    }

    private function classify(object $r, array $docIssues, bool $legacyExit): array
    {
        $s = [];
        $c = [];
        if ($r->warehouse_id === null) {
            return [['VALID'], ['Không phát sinh giao dịch / số dư']];
        }
        if ($r->mv_qty < -self::QTY_EPS || $r->expected_avco_qty < -self::QTY_EPS || ($r->avco_qty ?? 0) < -self::QTY_EPS) {
            $s[] = 'NEGATIVE_STOCK';
            $c[] = "Sổ kho âm {$r->mv_qty} (xuất nhiều hơn nhập)";
        }
        if ($r->avco_qty === null) {
            if (abs($r->expected_avco_qty) > self::QTY_EPS) {
                $s[] = 'MISSING_BALANCE';
                $c[] = "Chưa có dòng AVCO; hệ thống dùng fallback movement = {$r->fallback_qty}";
                if (abs((float) $r->fallback_qty - $r->expected_avco_qty) > self::QTY_EPS) {
                    $s[] = 'AVAILABILITY_MISMATCH';
                    $c[] = "Tồn khả dụng fallback {$r->fallback_qty} ≠ kỳ vọng {$r->expected_avco_qty}";
                }
            }
        } elseif ($r->qty_diff > self::QTY_EPS) {
            $s[] = 'PHANTOM_STOCK';
            $c[] = "AVCO {$r->avco_qty} > kỳ vọng {$r->expected_avco_qty} (tồn ảo {$r->qty_diff})";
        } elseif ($r->qty_diff < -self::QTY_EPS) {
            $s[] = 'QUANTITY_MISMATCH';
            $c[] = "AVCO {$r->avco_qty} < kỳ vọng {$r->expected_avco_qty} (thiếu {$r->qty_diff}) — có hàng nhưng không xuất được";
        }
        if ($r->avco_value !== null && abs($r->value_diff) > 0.005) {
            if (abs($r->value_diff) > self::ROUNDING_VND && abs($r->expected_avco_qty) <= self::QTY_EPS && abs($r->avco_qty) <= self::QTY_EPS) {
                // Hết hàng: AVCO = 0 là đúng; phần dư nằm ở sổ kho/TK 156 do giá vốn các lần xuất trước bị tính lệch
                $s[] = 'ACCOUNTING_MISMATCH';
                $c[] = "Hết hàng nhưng sổ kho/TK156 còn dư giá trị {$r->expected_avco_value}đ — cần bút toán điều chỉnh giá vốn (PENDING_DECISION)";
            } elseif (abs($r->value_diff) <= self::ROUNDING_VND) {
                $s[] = 'EXPECTED_DIFFERENCE';
                $c[] = "Chênh giá trị {$r->value_diff}đ do làm tròn";
            } else {
                $s[] = 'VALUE_MISMATCH';
                $c[] = "Giá trị AVCO lệch sổ kho {$r->value_diff}đ";
            }
        }
        if (abs($r->lot_issued - $r->alloc_qty) > self::QTY_EPS) {
            $s[] = 'ALLOCATION_MISMATCH';
            $c[] = "Lô đã xuất {$r->lot_issued} ≠ Σ allocation hiệu lực {$r->alloc_qty}";
        }
        if ($r->inactive && $r->available_qty > self::QTY_EPS) {
            $s[] = 'AVAILABILITY_MISMATCH';
            $c[] = 'Sản phẩm ngừng hoạt động nhưng còn tồn khả dụng (không chọn được trên form xuất)';
        }
        foreach ($docIssues as [$status, $msg]) {
            $s[] = $status;
            $c[] = $msg;
        }
        if (in_array($r->initialized_from, ['auto_from_entries', 'reconcile_rebuild'], true) || $legacyExit) {
            $s[] = 'LEGACY_DATA';
            $c[] = 'Dữ liệu trước AVCO/khởi tạo lại (' . ($r->initialized_from ?? 'n/a') . ($legacyExit ? ', có phiếu xuất legacy' : '') . ')';
        }
        return [$s, $c];
    }

    private function filter(Collection $rows, array $f): Collection
    {
        $docRows = ! empty($f['document']) ? $this->docs->rowsForDocument($f['document']) : null;
        $projRows = ! empty($f['project']) ? $this->docs->rowsForProject((int) $f['project']) : null;

        return $rows->filter(function ($r) use ($f, $docRows, $projRows) {
            $key = $r->product_id . '-' . $r->warehouse_id;
            return (empty($f['product']) || in_array((int) $r->product_id, $f['product'], true))
                && (empty($f['warehouse']) || (int) $r->warehouse_id === (int) $f['warehouse'])
                && ($docRows === null || isset($docRows[$key]))
                && ($projRows === null || isset($projRows[$key]))
                && (empty($f['status']) || in_array($f['status'], $r->statuses, true));
        })->values();
    }

    private function sql(): string
    {
        return "
            WITH mv AS (
                SELECT product_id p, warehouse_id w, SUM(quantity) q, SUM(COALESCE(amount,0)) v,
                       SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) qi,
                       SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) qo,
                       SUM(CASE WHEN project_id IS NULL THEN quantity ELSE 0 END) qnp
                FROM stock_movements WHERE status = 'active' GROUP BY product_id, warehouse_id
            ), lot AS (
                SELECT product_id p, warehouse_id w, SUM(received_qty - issued_qty) q,
                       SUM((received_qty - issued_qty) * COALESCE(unit_cost,0)) v,
                       SUM(received_qty) rq, SUM(issued_qty) iq
                FROM project_inventory_lots WHERE status <> 'cancelled' GROUP BY product_id, warehouse_id
            ), al AS (
                SELECT l.product_id p, l.warehouse_id w, SUM(a.allocated_qty) q
                FROM stock_exit_item_lot_allocations a JOIN project_inventory_lots l ON l.id = a.project_inventory_lot_id
                WHERE a.voided_at IS NULL AND l.status <> 'cancelled' GROUP BY l.product_id, l.warehouse_id
            ), k AS (
                SELECT p, w FROM mv UNION SELECT product_id, warehouse_id FROM inventory_balances UNION SELECT p, w FROM lot
            ), k2 AS (
                SELECT pr.id p, k.w FROM products pr LEFT JOIN k ON k.p = pr.id
            )
            SELECT k2.p AS product_id, k2.w AS warehouse_id, pr.code AS product_code, pr.name AS product_name,
                   pr.unit, wh.name AS warehouse_name,
                   CASE WHEN pr.is_active AND pr.deleted_at IS NULL THEN 0 ELSE 1 END AS inactive,
                   b.qty_on_hand AS avco_qty, b.value_on_hand AS avco_value, b.avg_cost, b.initialized_from,
                   COALESCE(mv.q,0) mv_qty, COALESCE(mv.v,0) mv_value, COALESCE(mv.qi,0) in_qty, COALESCE(mv.qo,0) out_qty,
                   COALESCE(mv.qnp,0) fallback_qty,
                   COALESCE(lot.q,0) lot_qty, COALESCE(lot.v,0) lot_value, COALESCE(lot.rq,0) lot_received,
                   COALESCE(lot.iq,0) lot_issued, COALESCE(al.q,0) alloc_qty
            FROM k2
            JOIN products pr ON pr.id = k2.p
            LEFT JOIN warehouses wh ON wh.id = k2.w
            LEFT JOIN inventory_balances b ON b.product_id = k2.p AND b.warehouse_id = k2.w
            LEFT JOIN mv ON mv.p = k2.p AND mv.w = k2.w
            LEFT JOIN lot ON lot.p = k2.p AND lot.w = k2.w
            LEFT JOIN al ON al.p = k2.p AND al.w = k2.w
            ORDER BY k2.p, k2.w";
    }
}
