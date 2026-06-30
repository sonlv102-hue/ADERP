<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\InventoryReportExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function __construct(private InventoryReportService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'warehouse_id', 'category_id']);
        $filters['date_from'] ??= now()->startOfYear()->toDateString();
        $filters['date_to']   ??= now()->toDateString();

        $rows    = $this->service->buildPaginatedRows($filters);
        $summary = $this->service->buildSummary($filters);

        $warehouses = DB::table('warehouses')->orderBy('name')->get(['id', 'name']);
        $categories = DB::table('product_categories')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Reports/Inventory/Index', [
            'rows'       => $rows,
            'summary'    => $summary,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'filters'    => $filters,
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
        $product        = null;
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
                ->whereRaw("(sm.status IS NULL OR sm.status = 'active')")
                ->where('sm.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
                ->where(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"), '<', $dateFrom)
                ->sum('sm.quantity');

            $openingValue = (float) DB::table('stock_movements as sm')
                ->leftJoin('stock_entries as se', function ($join) {
                    $join->on('sm.source_id', '=', 'se.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
                })
                ->leftJoin('stock_exits as sx', function ($join) {
                    $join->on('sm.source_id', '=', 'sx.id')
                         ->where('sm.source_type', '=', 'App\\Models\\StockExit');
                })
                ->whereRaw("(sm.status IS NULL OR sm.status = 'active')")
                ->where('sm.product_id', $productId)
                ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
                ->where(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"), '<', $dateFrom)
                ->sum(DB::raw('COALESCE(sm.amount, 0)'));

            // Phát sinh trong kỳ — dùng ngày phiếu NK/XK
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
                ->whereRaw("(sm.status IS NULL OR sm.status = 'active')")
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
                    'se.code as entry_code',
                    'sx.code as exit_code',
                ])
                ->orderBy(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"))
                ->orderBy('sm.id')
                ->get();

            $runningBalance = $openingBalance;
            $runningValue   = $openingValue;
            $rows = $movements->map(function ($m) use (&$runningBalance, &$runningValue) {
                $qty     = (float) $m->quantity;
                $qtyIn   = $qty > 0 ? $qty : 0;
                $qtyOut  = $qty < 0 ? abs($qty) : 0;
                $amount  = (float) ($m->amount ?? 0);
                $runningBalance += $qty;
                $runningValue   += $amount;
                $unitCost        = (float) ($m->unit_cost ?? 0);

                $refType = class_basename($m->reference_type ?? '');
                $desc    = match ($refType) {
                    'StockEntry'              => "Nhập kho " . ($m->entry_code ?? "NK-{$m->reference_id}"),
                    'StockExit'               => "Xuất kho " . ($m->exit_code  ?? "XK-{$m->reference_id}"),
                    'StockTransfer'           => "Chuyển kho CK-{$m->reference_id}",
                    'SalesReturn'             => "Trả hàng bán TH-{$m->reference_id}",
                    'PurchaseReturn'          => "Trả hàng mua THM-{$m->reference_id}",
                    'InventoryCount'          => "Điều chỉnh IK-{$m->reference_id}",
                    'InventoryOpeningBalance' => "Tồn kho đầu kỳ",
                    default                   => $refType . " #{$m->reference_id}",
                };

                return [
                    'date'          => substr($m->date, 0, 10),
                    'description'   => $desc,
                    'warehouse'     => $m->warehouse_name,
                    'qty_in'        => $qtyIn,
                    'qty_out'       => $qtyOut,
                    'balance'       => $runningBalance,
                    'unit_cost'     => $unitCost,
                    'value_in'      => $amount > 0 ? $amount : 0,
                    'value_out'     => $amount < 0 ? abs($amount) : 0,
                    'value_balance' => $runningValue,
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
