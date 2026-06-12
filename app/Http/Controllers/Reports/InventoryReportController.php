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
                 SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity < 0 THEN ABS(quantity) ELSE 0 END) as stock_out,
                 MAX(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity > 0 THEN DATE(created_at) END) as last_in_date,
                 MAX(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity < 0 THEN DATE(created_at) END) as last_out_date",
                [$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]
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
                'value_begin'  => $begin * $cost,
                'value_in'     => $in  * $cost,
                'value_out'    => $out * $cost,
                'value_end'    => $end * $cost,
                'last_in_date' => $row->last_in_date,
                'last_out_date'=> $row->last_out_date,
            ];
        });

        // Summary từ TOÀN BỘ sản phẩm (không giới hạn trang hiện tại)
        // Dùng query mới độc lập để tránh binding conflict khi reuse $smAgg
        $smAggSummary = DB::table('stock_movements')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw(
                "product_id,
                 SUM(CASE WHEN DATE(created_at) < ? THEN quantity ELSE 0 END) as stock_begin,
                 SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity > 0 THEN quantity ELSE 0 END) as stock_in,
                 SUM(CASE WHEN DATE(created_at) BETWEEN ? AND ? AND quantity < 0 THEN ABS(quantity) ELSE 0 END) as stock_out",
                [$dateFrom, $dateFrom, $dateTo, $dateFrom, $dateTo]
            )
            ->groupBy('product_id');

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
                "SUM(COALESCE(sms.stock_begin, 0) * products.cost_price) as begin_val,
                 SUM(COALESCE(sms.stock_in,    0) * products.cost_price) as in_val,
                 SUM(COALESCE(sms.stock_out,   0) * products.cost_price) as out_val,
                 SUM((COALESCE(sms.stock_begin, 0) + COALESCE(sms.stock_in, 0) - COALESCE(sms.stock_out, 0)) * products.cost_price) as end_val"
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

            // Tồn đầu kỳ (trước date_from)
            $openingBalance = (float) DB::table('stock_movements')
                ->where('product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->where('created_at', '<', $dateFrom . ' 00:00:00')
                ->sum('quantity');

            // Đơn giá bình quân đầu kỳ
            $openingValue = $openingBalance * (float) ($product->cost_price ?? 0);

            // Các phát sinh trong kỳ
            $movements = DB::table('stock_movements')
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
                ->where('stock_movements.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('stock_movements.warehouse_id', $warehouseId))
                ->whereBetween(DB::raw('DATE(stock_movements.created_at)'), [$dateFrom, $dateTo])
                ->select([
                    'stock_movements.id',
                    'stock_movements.created_at as date',
                    'stock_movements.type as movement_type',
                    'stock_movements.quantity',
                    'stock_movements.source_type as reference_type',
                    'stock_movements.source_id as reference_id',
                    'warehouses.name as warehouse_name',
                ])
                ->orderBy('stock_movements.created_at')
                ->get();

            $runningBalance = $openingBalance;
            $rows = $movements->map(function ($m) use (&$runningBalance, $product) {
                $qty     = (float) $m->quantity;
                $qtyIn   = $qty > 0 ? $qty : 0;
                $qtyOut  = $qty < 0 ? abs($qty) : 0;
                $runningBalance += $qty;
                $unitCost        = (float) ($product->cost_price ?? 0);

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
