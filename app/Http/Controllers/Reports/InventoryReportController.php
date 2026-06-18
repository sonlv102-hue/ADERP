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
        // Dùng entry_date/exit_date từ phiếu NK/XK; fallback DATE(created_at) cho các nguồn khác
        $smAgg = DB::table('stock_movements as sm')
            ->leftJoin('stock_entries as se', function ($join) {
                $join->on('sm.source_id', '=', 'se.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
            })
            ->leftJoin('stock_exits as sx', function ($join) {
                $join->on('sm.source_id', '=', 'sx.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->selectRaw(
                "sm.product_id,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) < ? THEN sm.quantity ELSE 0 END) as stock_begin,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity > 0 THEN sm.quantity ELSE 0 END) as stock_in,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as stock_out,
                 MAX(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity > 0 THEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) END) as last_in_date,
                 MAX(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity < 0 THEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) END) as last_out_date,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) < ? THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_begin,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity > 0 THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_in,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(COALESCE(sm.amount, 0)) ELSE 0 END) as amount_out",
                [$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo]
            )
            ->groupBy('sm.product_id');

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
                DB::raw('sm_agg.last_in_date'),
                DB::raw('sm_agg.last_out_date'),
                DB::raw('COALESCE(sm_agg.amount_begin, 0) as amount_begin'),
                DB::raw('COALESCE(sm_agg.amount_in, 0) as amount_in'),
                DB::raw('COALESCE(sm_agg.amount_out, 0) as amount_out'),
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
            $begin     = (float) $row->stock_begin;
            $in        = (float) $row->stock_in;
            $out       = (float) $row->stock_out;
            $end       = $begin + $in - $out;
            $cost      = (float) $row->cost_price;
            $beginVal  = (float) $row->amount_begin;
            $inVal     = (float) $row->amount_in;
            $outVal    = (float) $row->amount_out;

            return [
                'id'           => $row->id,
                'code'         => $row->code,
                'name'         => $row->name,
                'unit'         => $row->unit,
                'category'     => $row->category,
                'cost_price'   => $cost,
                'stock_begin'  => $begin,
                'stock_in'     => $in,
                'stock_out'    => $out,
                'stock_end'    => $end,
                'value_begin'  => $beginVal,
                'value_in'     => $inVal,
                'value_out'    => $outVal,
                'value_end'    => $beginVal + $inVal - $outVal,
                'last_in_date' => $row->last_in_date,
                'last_out_date'=> $row->last_out_date,
            ];
        });

        // Summary từ TOÀN BỘ sản phẩm (không giới hạn trang hiện tại)
        // Dùng query mới độc lập để tránh binding conflict khi reuse $smAgg
        $smAggSummary = DB::table('stock_movements as sm')
            ->leftJoin('stock_entries as se', function ($join) {
                $join->on('sm.source_id', '=', 'se.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
            })
            ->leftJoin('stock_exits as sx', function ($join) {
                $join->on('sm.source_id', '=', 'sx.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->selectRaw(
                "sm.product_id,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) < ? THEN sm.quantity ELSE 0 END) as stock_begin,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity > 0 THEN sm.quantity ELSE 0 END) as stock_in,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as stock_out,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) < ? THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_begin,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity > 0 THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_in,
                 SUM(CASE WHEN COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(COALESCE(sm.amount, 0)) ELSE 0 END) as amount_out",
                [$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo]
            )
            ->groupBy('sm.product_id');

        $summaryAgg = DB::table('products')
            ->leftJoinSub($smAggSummary, 'sms', 'sms.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('products.code', 'ilike', "%{$search}%")
                       ->orWhere('products.name', 'ilike', "%{$search}%")
                )
            )
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw(
                "SUM(COALESCE(sms.amount_begin, 0)) as begin_val,
                 SUM(COALESCE(sms.amount_in,    0)) as in_val,
                 SUM(COALESCE(sms.amount_out,   0)) as out_val,
                 SUM(COALESCE(sms.amount_begin, 0) + COALESCE(sms.amount_in, 0) - COALESCE(sms.amount_out, 0)) as end_val"
            )
            ->first();

        $summary = [
            'total_begin_value' => (float) ($summaryAgg->begin_val ?? 0),
            'total_in_value'    => (float) ($summaryAgg->in_val    ?? 0),
            'total_out_value'   => (float) ($summaryAgg->out_val   ?? 0),
            'total_end_value'   => (float) ($summaryAgg->end_val   ?? 0),
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

    public function stockCard(Request $request): Response
    {
        $productId   = $request->input('product_id');
        $warehouseId = $request->input('warehouse_id');
        $dateFrom    = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo      = $request->input('date_to',   now()->toDateString());

        $products   = DB::table('products')->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name', 'unit', 'cost_price']);
        $warehouses = DB::table('warehouses')->orderBy('name')->get(['id', 'name']);

        $rows = collect();
        $product = null;
        $openingBalance = 0;
        $openingValue   = 0;

        if ($productId) {
            $product = $products->firstWhere('id', (int) $productId);

            // Tồn đầu kỳ (trước date_from) — dùng ngày phiếu NK/XK
            $openingBalance = (float) DB::table('stock_movements as sm')
                ->leftJoin('stock_entries as se', function ($join) {
                    $join->on('sm.source_id', '=', 'se.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
                })
                ->leftJoin('stock_exits as sx', function ($join) {
                    $join->on('sm.source_id', '=', 'sx.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockExit');
                })
                ->where('sm.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
                ->where(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"), '<', $dateFrom)
                ->sum('sm.quantity');

            // Giá trị đầu kỳ — tính từ sm.amount thực tế (không dùng cost_price hiện tại)
            $openingValue = (float) DB::table('stock_movements as sm')
                ->leftJoin('stock_entries as se', function ($join) {
                    $join->on('sm.source_id', '=', 'se.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
                })
                ->leftJoin('stock_exits as sx', function ($join) {
                    $join->on('sm.source_id', '=', 'sx.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockExit');
                })
                ->where('sm.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
                ->where(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"), '<', $dateFrom)
                ->sum(DB::raw('COALESCE(sm.amount, 0)'));

            // Các phát sinh trong kỳ — dùng ngày phiếu NK/XK
            $movements = DB::table('stock_movements as sm')
                ->join('products', 'products.id', '=', 'sm.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'sm.warehouse_id')
                ->leftJoin('stock_entries as se', function ($join) {
                    $join->on('sm.source_id', '=', 'se.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
                })
                ->leftJoin('stock_exits as sx', function ($join) {
                    $join->on('sm.source_id', '=', 'sx.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockExit');
                })
                ->where('sm.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
                ->whereBetween(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"), [$dateFrom, $dateTo])
                ->select([
                    'sm.id',
                    DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) as date"),
                    'sm.type as movement_type',
                    'sm.quantity',
                    'sm.source_type as reference_type',
                    'sm.source_id as reference_id',
                    'warehouses.name as warehouse_name',
                    'sm.unit_cost',
                    'sm.amount',
                ])
                ->orderBy(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"))
                ->orderBy('sm.id')
                ->get();

            $runningBalance = $openingBalance;
            $rows = $movements->map(function ($m) use (&$runningBalance, $product) {
                $qty     = (float) $m->quantity;
                $qtyIn   = $qty > 0 ? $qty : 0;
                $qtyOut  = $qty < 0 ? abs($qty) : 0;
                $runningBalance += $qty;
                $unitCost        = (float) ($m->unit_cost ?? $product->cost_price ?? 0);

                // Derive description from reference_type
                $refType = class_basename($m->reference_type ?? '');
                $desc    = match ($refType) {
                    'StockEntry'    => "Nhập kho NK-{$m->reference_id}",
                    'StockExit'     => "Xuất kho XK-{$m->reference_id}",
                    'StockTransfer' => "Chuyển kho CK-{$m->reference_id}",
                    'SalesReturn'   => "Trả hàng bán TH-{$m->reference_id}",
                    'PurchaseReturn' => "Trả hàng mua THM-{$m->reference_id}",
                    'InventoryCount' => "Điều chỉnh IK-{$m->reference_id}",
                    'InventoryOpeningBalance' => "Tồn kho đầu kỳ",
                    default         => $refType . " #{$m->reference_id}",
                };

                return [
                    'date'            => substr($m->date, 0, 10),
                    'description'     => $desc,
                    'warehouse'       => $m->warehouse_name,
                    'qty_in'          => $qtyIn,
                    'qty_out'         => $qtyOut,
                    'balance'         => $runningBalance,
                    'unit_cost'       => $unitCost,
                    'value_in'        => $qtyIn  * $unitCost,
                    'value_out'       => $qtyOut * $unitCost,
                    'value_balance'   => $runningBalance * $unitCost,
                ];
            });
        }

        return Inertia::render('Reports/Inventory/StockCard', [
            'rows'           => $rows,
            'product'        => $product,
            'openingBalance' => $openingBalance,
            'openingValue'   => $openingValue,
            'products'       => $products,
            'warehouses'     => $warehouses,
            'filters'        => $request->only(['product_id', 'warehouse_id', 'date_from', 'date_to']),
        ]);
    }
}
