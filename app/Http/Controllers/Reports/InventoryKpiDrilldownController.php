<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\InventoryKpiDrilldownExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryKpiDrilldownService;
use App\Services\Reports\InventoryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Drill-down 4 KPI của /reports/inventory (xem InventoryReportController::index()).
 * Trả JSON (được gọi qua axios từ modal, không phải Inertia::render) — route nằm
 * trong group reports.* nên đã được gate bởi middleware can:reports.view như report gốc.
 */
class InventoryKpiDrilldownController extends Controller
{
    private const KPI_TOTAL_KEY = [
        'begin' => 'total_begin_value',
        'in'    => 'total_in_value',
        'out'   => 'total_out_value',
        'end'   => 'total_end_value',
    ];

    public function __construct(
        private InventoryReportService $reportService,
        private InventoryKpiDrilldownService $drilldown,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kpi'          => 'required|in:begin,in,out,end',
            'tab'          => 'required|in:summary,products,documents,reconcile',
            'search'       => 'nullable|string|max:255',
            'date_from'    => 'nullable|date',
            'date_to'      => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'category_id'  => 'nullable|integer',
            'page'         => 'nullable|integer|min:1',
            'per_page'     => 'nullable|integer|min:1|max:100',
            'sort'         => 'nullable|string',
            'direction'    => 'nullable|in:asc,desc',
        ]);

        $filters = array_filter([
            'search'       => $data['search']       ?? null,
            'date_from'    => $data['date_from']     ?? now()->startOfYear()->toDateString(),
            'date_to'      => $data['date_to']       ?? now()->toDateString(),
            'warehouse_id' => $data['warehouse_id']  ?? null,
            'category_id'  => $data['category_id']   ?? null,
        ], fn ($v) => $v !== null);

        $kpi     = $data['kpi'];
        $perPage = $data['per_page'] ?? 30;

        return match ($data['tab']) {
            'products'  => response()->json($this->drilldown->paginatedProducts($filters, $kpi, $perPage)->toArray()),
            'documents' => response()->json(
                $this->documents($filters, $kpi, $perPage, $data['sort'] ?? null, $data['direction'] ?? 'desc')
            ),
            'reconcile' => response()->json($this->reconcile($filters, $kpi)),
            default     => response()->json($this->summary($filters, $kpi)),
        };
    }

    /**
     * Gắn link chứng từ gốc (StockEntry/StockExit) — ENFORCE quyền ở server, không chỉ ẩn ở UI:
     * row['linkable'] từ InventoryKpiDrilldownQuery::map() chỉ mới xét LOẠI chứng từ có trang
     * chi tiết; ở đây ép thêm điều kiện quyền thực tế của user đang đăng nhập.
     */
    private function documents(array $filters, string $kpi, int $perPage, ?string $sort, string $direction): array
    {
        $paginator = $this->drilldown->paginatedDocuments($filters, $kpi, $perPage, $sort, $direction);

        $canViewEntry = (bool) auth()->user()?->can('warehouse.stock_entries.view');
        $canViewExit  = (bool) auth()->user()?->can('warehouse.stock_exits.view');

        $paginator->getCollection()->transform(function (array $row) use ($canViewEntry, $canViewExit) {
            if ($row['linkable']) {
                $row['linkable'] = ($row['source_type'] === 'StockEntry' && $canViewEntry)
                    || ($row['source_type'] === 'StockExit' && $canViewExit);
            }
            return $row;
        });

        return $paginator->toArray();
    }

    private function summary(array $filters, string $kpi): array
    {
        $kpiValue = (float) ($this->reportService->buildSummary($filters)[self::KPI_TOTAL_KEY[$kpi]] ?? 0);
        $agg      = $this->drilldown->aggregate($filters, $kpi);
        $nullStats = $this->drilldown->nullValueStats($filters, $kpi);

        return [
            'kpi_value'       => $kpiValue,
            'document_count'  => $agg['count'],
            'total_qty'       => $agg['total_qty'],
            'null_value_stats' => $nullStats,
        ];
    }

    private function reconcile(array $filters, string $kpi): array
    {
        $kpiValue   = (float) ($this->reportService->buildSummary($filters)[self::KPI_TOTAL_KEY[$kpi]] ?? 0);
        $agg        = $this->drilldown->aggregate($filters, $kpi);
        $detailTotal = round($agg['total_amount'], 2);
        $diff        = round($kpiValue - $detailTotal, 2);

        return [
            'kpi_value'        => $kpiValue,
            'detail_total'     => $detailTotal,
            'diff'             => $diff,
            'matched'          => abs($diff) < 0.01,
            'null_value_stats' => $this->drilldown->nullValueStats($filters, $kpi),
        ];
    }

    public function export(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'kpi'          => 'required|in:begin,in,out,end',
            'search'       => 'nullable|string|max:255',
            'date_from'    => 'nullable|date',
            'date_to'      => 'nullable|date',
            'warehouse_id' => 'nullable|integer',
            'category_id'  => 'nullable|integer',
        ]);

        $kpi = $data['kpi'];
        unset($data['kpi']);

        return Excel::download(
            new InventoryKpiDrilldownExport($data, $kpi),
            'inventory-drilldown-' . $kpi . '-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
