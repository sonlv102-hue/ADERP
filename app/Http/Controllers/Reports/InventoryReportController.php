<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\InventoryReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function index(Request $request): Response
    {
        $search      = $request->input('search');
        $dateFrom    = $request->input('date_from', now()->startOfYear()->toDateString());
        $dateTo      = $request->input('date_to',   now()->toDateString());
        $warehouseId = $request->input('warehouse_id');
        $categoryId  = $request->input('category_id');

        // Pre-aggregate stock_movements once (1 query) thay vì 3 correlated subqueries × N rows
        $smAgg = DB::table('stock_movements')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw(
                "product_id,
                 SUM(CASE WHEN DATE(created_at) < ? THEN quantity ELSE 0 END) as stock_begin,
                 SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                 SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity < 0 THEN ABS(quantity) ELSE 0 END) as stock_out",
                [$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo]
            )
            ->groupBy('product_id');

        $query = DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoinSub($smAgg, 'sm_agg', 'sm_agg.product_id', '=', 'products.id')
            ->select([
                'products.id',
                'products.code',
                'products.name',
                'products.unit',
                'products.cost_price',
                'product_categories.name as category',
                DB::raw('COALESCE(sm_agg.stock_begin, 0) as stock_begin'),
                DB::raw('COALESCE(sm_agg.stock_in, 0) as stock_in'),
                DB::raw('COALESCE(sm_agg.stock_out, 0) as stock_out'),
            ])
            ->whereNull('products.deleted_at')
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('products.code', 'ilike', "%{$search}%")
                       ->orWhere('products.name', 'ilike', "%{$search}%")
                )
            )
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->orderBy('products.code');

        $rows = $query->paginate(30);

        $rows->through(function ($row) {
            $begin = (float) $row->stock_begin;
            $in    = (float) $row->stock_in;
            $out   = (float) $row->stock_out;
            $end   = $begin + $in - $out;
            $cost  = (float) $row->cost_price;

            return [
                'id'          => $row->id,
                'code'        => $row->code,
                'name'        => $row->name,
                'unit'        => $row->unit,
                'category'    => $row->category,
                'cost_price'  => $cost,
                'stock_begin' => $begin,
                'stock_in'    => $in,
                'stock_out'   => $out,
                'stock_end'   => $end,
                'value_end'   => $end * $cost,
            ];
        });

        $summary = [
            'total_begin_value' => collect($rows->items())->sum(fn ($r) => $r['stock_begin'] * $r['cost_price']),
            'total_in_value'    => collect($rows->items())->sum(fn ($r) => $r['stock_in']    * $r['cost_price']),
            'total_out_value'   => collect($rows->items())->sum(fn ($r) => $r['stock_out']   * $r['cost_price']),
            'total_end_value'   => collect($rows->items())->sum('value_end'),
        ];

        $warehouses = DB::table('warehouses')->orderBy('name')->get(['id', 'name']);
        $categories = DB::table('product_categories')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Reports/Inventory/Index', [
            'rows'       => $rows,
            'summary'    => $summary,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'filters'    => $request->only(['search', 'date_from', 'date_to', 'warehouse_id', 'category_id']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new InventoryReportExport($request->all()),
            'inventory-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
