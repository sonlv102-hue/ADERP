<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\InventoryTransactionReportExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryTransactionReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryTransactionReportController extends Controller
{
    public function __construct(private InventoryTransactionReportService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'warehouse_id', 'category_id', 'product_id']);
        $filters['date_from'] ??= now()->startOfYear()->toDateString();
        $filters['date_to']   ??= now()->toDateString();

        if (! empty($filters['product_id'])) {
            $filters['product_name'] = DB::table('products')->where('id', $filters['product_id'])->value('name');
        }

        $warehouses = DB::table('warehouses')->orderBy('name')->get(['id', 'name']);
        $categories = DB::table('product_categories')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Reports/Warehouse/InventoryTransactions', [
            'rows'       => $this->service->buildProductPage($filters),
            'warehouses' => $warehouses,
            'categories' => $categories,
            'filters'    => $filters,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'warehouse_id', 'category_id', 'product_id']);
        $filters['date_from'] ??= now()->startOfYear()->toDateString();
        $filters['date_to']   ??= now()->toDateString();

        return Excel::download(
            new InventoryTransactionReportExport($filters),
            'nhap-xuat-ton-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
