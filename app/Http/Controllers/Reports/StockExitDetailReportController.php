<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\StockExitDetailExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\StockMovementDetailReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockExitDetailReportController extends Controller
{
    public function __construct(private StockMovementDetailReportService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'warehouse_id']);
        $filters['date_from'] ??= now()->startOfYear()->toDateString();
        $filters['date_to']   ??= now()->toDateString();

        $rows = $this->service->buildStockExitQuery($filters)
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($row) => StockMovementDetailReportService::mapStockExitRow($row));

        $warehouses = DB::table('warehouses')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Reports/Warehouse/StockExitDetails', [
            'rows'       => $rows,
            'filters'    => $filters,
            'warehouses' => $warehouses,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new StockExitDetailExport($request->all()),
            'chi-tiet-xuat-kho-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
