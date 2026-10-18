<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drill-down chứng từ cho 4 KPI của Báo cáo Tồn kho (/reports/inventory).
 *
 * Nguyên tắc bắt buộc (đã duyệt): mọi điều kiện lọc dưới đây được CHÉP NGUYÊN VĂN
 * từ InventoryReportService (ACTIVE_FILTER, DOC_DATE, products.deleted_at) — không
 * tái dùng trực tiếp vì 2 const đó là private — để đảm bảo tập hợp dòng chứng từ ở
 * đây khớp 1:1 với từng KPI tổng hợp, phục vụ đối soát ở tab "Đối soát".
 * Không sửa công thức giá vốn/tồn kho.
 */
class InventoryKpiDrilldownService
{
    private const DOC_DATE = InventoryKpiDrilldownQuery::DOC_DATE;

    /** Áp điều kiện ngày/dấu theo đúng CASE trong InventoryReportService::buildSmAggregate() */
    private function applyKpiScope(Builder $query, string $kpi, string $dateFrom, string $dateTo): Builder
    {
        return match ($kpi) {
            'begin' => $query->whereRaw(self::DOC_DATE . ' < ?', [$dateFrom]),
            'in'    => $query->whereRaw(self::DOC_DATE . ' BETWEEN ? AND ?', [$dateFrom, $dateTo])->where('sm.quantity', '>', 0),
            'out'   => $query->whereRaw(self::DOC_DATE . ' BETWEEN ? AND ?', [$dateFrom, $dateTo])->where('sm.quantity', '<', 0),
            'end'   => $query->whereRaw(self::DOC_DATE . ' <= ?', [$dateTo]),
            default => throw new \InvalidArgumentException("KPI không hợp lệ: {$kpi}"),
        };
    }

    /**
     * Khớp dấu với amountExpr()/qtyExpr() cho hiển thị dòng chi tiết (Chứng từ + Excel) — nếu
     * không, tổng SL/Giá trị trên tab Chứng từ sẽ âm trong khi KPI/tab Theo sản phẩm dương.
     * amount dùng CASE (không COALESCE) để giữ NULL — cần cho cờ has_source_value.
     */
    private function selectColumns(Builder $query, string $kpi): Builder
    {
        $amountRaw = $kpi === 'out' ? 'CASE WHEN sm.amount IS NULL THEN NULL ELSE ABS(sm.amount) END' : 'sm.amount';

        return $query->select([
            'sm.id', 'sm.unit_cost', 'sm.notes', 'sm.source_type', 'sm.source_id as document_id',
            DB::raw($this->qtyExpr($kpi) . ' as quantity'),
            DB::raw($amountRaw . ' as amount'),
            'p.code as product_code', 'p.name as product_name', 'p.unit',
            'w.name as warehouse_name',
            DB::raw(self::DOC_DATE . ' as doc_date'),
            DB::raw('COALESCE(se.code, sx.code, st.code, sr.code, pr.code, ic.code) as doc_code'),
        ]);
    }

    /** amount_out dùng ABS (movement xuất lưu amount âm) — chép đúng quy ước InventoryReportService::buildSmAggregate. */
    private function amountExpr(string $kpi): string
    {
        return $kpi === 'out' ? 'ABS(COALESCE(sm.amount, 0))' : 'COALESCE(sm.amount, 0)';
    }

    /** quantity_out cũng dùng ABS cùng lý do — khớp cột stock_out của InventoryReportService::mapRow(). */
    private function qtyExpr(string $kpi): string
    {
        return $kpi === 'out' ? 'ABS(sm.quantity)' : 'sm.quantity';
    }

    public function query(array $filters, string $kpi): Builder
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo   = $filters['date_to']   ?? now()->toDateString();

        $query = $this->applyKpiScope(InventoryKpiDrilldownQuery::baseQuery($filters), $kpi, $dateFrom, $dateTo);

        return $this->selectColumns($query, $kpi);
    }

    /** Phân trang cho tab "Chứng từ". Sort whitelist để tránh SQL injection qua tên cột. */
    public function paginatedDocuments(array $filters, string $kpi, int $perPage = 30, ?string $sort = null, string $direction = 'desc'): LengthAwarePaginator
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $sortable  = [
            'doc_date'     => DB::raw(self::DOC_DATE),
            'amount'       => DB::raw($this->amountExpr($kpi)),
            'quantity'     => DB::raw($this->qtyExpr($kpi)),
            'product_code' => 'p.code',
        ];
        $orderCol = $sortable[$sort] ?? $sortable['doc_date'];

        return $this->query($filters, $kpi)
            ->orderBy($orderCol, $direction)
            ->orderBy('sm.id', $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn ($row) => InventoryKpiDrilldownQuery::map($row));
    }

    /**
     * Phân trang cho tab "Theo sản phẩm" — CHỈ các sản phẩm có ít nhất 1 dòng movement khớp đúng
     * kpi đang xem (không phải toàn bộ catalogue). Group theo product nên số sản phẩm ở đây luôn
     * <= số dòng ở tab Chứng từ (1 SP có thể có nhiều dòng chứng từ) và SUM(value) khớp đối soát.
     */
    public function paginatedProducts(array $filters, string $kpi, int $perPage = 30): LengthAwarePaginator
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo   = $filters['date_to']   ?? now()->toDateString();

        $query = $this->applyKpiScope(InventoryKpiDrilldownQuery::baseQuery($filters), $kpi, $dateFrom, $dateTo)
            ->groupBy('p.id', 'p.code', 'p.name', 'p.unit', 'pc.name')
            ->selectRaw(
                "p.id, p.code, p.name, p.unit, pc.name as category,
                 SUM({$this->qtyExpr($kpi)}) as qty, SUM({$this->amountExpr($kpi)}) as value"
            )
            ->orderBy('p.code');

        return $query->paginate($perPage)->withQueryString()->through(fn ($row) => [
            'id'       => $row->id,
            'code'     => $row->code,
            'name'     => $row->name,
            'unit'     => $row->unit,
            'category' => $row->category ?? '—',
            'qty'      => (float) $row->qty,
            'value'    => (float) $row->value,
        ]);
    }

    /**
     * Tổng giá trị/số lượng của TOÀN BỘ tập khớp filter — độc lập với phân trang, dùng để đối soát với KPI gốc.
     * KPI 'out' trong InventoryReportService::buildSmAggregate dùng ABS(amount) (amount_out luôn dương)
     * vì movement xuất lưu amount âm — chép đúng quy ước này để không lệch dấu khi đối soát.
     */
    public function aggregate(array $filters, string $kpi): array
    {
        $amountExpr = $this->amountExpr($kpi);

        $row = $this->applyKpiScope(InventoryKpiDrilldownQuery::baseQuery($filters), $kpi,
                $filters['date_from'] ?? now()->startOfYear()->toDateString(),
                $filters['date_to']   ?? now()->toDateString())
            ->selectRaw("COUNT(*) as cnt, COALESCE(SUM({$amountExpr}), 0) as total_amount, COALESCE(SUM(ABS(sm.quantity)), 0) as total_qty")
            ->first();

        return [
            'count'        => (int) $row->cnt,
            'total_amount' => (float) $row->total_amount,
            'total_qty'    => (float) $row->total_qty,
        ];
    }

    /**
     * Thống kê các giao dịch THIẾU giá trị nguồn (sm.amount IS NULL) trong phạm vi kpi đang xem —
     * hiển thị RIÊNG với số chênh lệch đối soát (quyết định A.1 đã duyệt: không suy diễn số tiền thiếu).
     */
    public function nullValueStats(array $filters, string $kpi): Collection
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo   = $filters['date_to']   ?? now()->toDateString();

        $rows = $this->applyKpiScope(InventoryKpiDrilldownQuery::baseQuery($filters), $kpi, $dateFrom, $dateTo)
            ->whereNull('sm.amount')
            ->selectRaw('sm.source_type, COUNT(*) as cnt, COALESCE(SUM(ABS(sm.quantity)), 0) as qty')
            ->groupBy('sm.source_type')
            ->get();

        return $rows->map(fn ($r) => [
            'source_type' => $r->source_type,
            'label'       => InventoryKpiDrilldownQuery::label($r->source_type),
            'count'       => (int) $r->cnt,
            'qty'         => (float) $r->qty,
        ]);
    }
}
