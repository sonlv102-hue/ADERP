<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\ExpenseDetailExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseDetailController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";

        // ── TK 632 – Giá vốn từ đơn hàng ────────────────────────────────────
        $cogs632Orders = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->select('orders.order_date as date', 'orders.code as ref',
                     'products.name as description',
                     DB::raw('order_items.quantity * COALESCE(products.cost_price, 0) as amount'))
            ->orderBy('orders.order_date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'ref' => $r->ref, 'tk' => '632',
                              'description' => 'Giá vốn đơn hàng: ' . $r->description, 'amount' => (float)$r->amount])
            ->all();

        $total632Orders = array_sum(array_column($cogs632Orders, 'amount'));

        // ── TK 632 – Vật tư dự án ────────────────────────────────────────────
        $cogs632Materials = DB::table('project_materials')
            ->join('projects', 'projects.id', '=', 'project_materials.project_id')
            ->join('products', 'products.id', '=', 'project_materials.product_id')
            ->whereBetween('projects.start_date', [$dateFrom, $dateTo])
            ->whereNotIn('projects.status', ['cancelled'])
            ->select('projects.start_date as date', 'projects.code as ref',
                     'products.name as description',
                     DB::raw('project_materials.quantity * project_materials.unit_price as amount'))
            ->orderBy('projects.start_date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'ref' => $r->ref, 'tk' => '632',
                              'description' => 'Vật tư dự án: ' . $r->description, 'amount' => (float)$r->amount])
            ->all();

        $total632Materials = array_sum(array_column($cogs632Materials, 'amount'));

        // ── TK 642 – Chi phí phát sinh dự án ────────────────────────────────
        $tk642 = DB::table('project_expenses')
            ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
            ->whereBetween('projects.start_date', [$dateFrom, $dateTo])
            ->whereNotIn('projects.status', ['cancelled'])
            ->select('projects.start_date as date', 'projects.code as ref',
                     'project_expenses.description as description',
                     'project_expenses.amount')
            ->orderBy('projects.start_date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'ref' => $r->ref, 'tk' => '642',
                              'description' => 'CP dự án: ' . $r->description, 'amount' => (float)$r->amount])
            ->all();

        $total642 = array_sum(array_column($tk642, 'amount'));

        // ── TK 641 – Hoa hồng bán hàng ───────────────────────────────────────
        $tk641 = DB::table('commissions')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->select(DB::raw("DATE(created_at) as date"),
                     'code as ref',
                     'description',
                     'amount')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'ref' => $r->ref, 'tk' => '641',
                              'description' => 'Hoa hồng: ' . ($r->description ?: $r->ref), 'amount' => (float)$r->amount])
            ->all();

        $total641 = array_sum(array_column($tk641, 'amount'));

        $groups = [
            ['tk' => '632', 'name' => 'Giá vốn hàng bán – Từ đơn hàng',    'rows' => $cogs632Orders,   'total' => $total632Orders],
            ['tk' => '632', 'name' => 'Giá vốn hàng bán – Vật tư dự án',   'rows' => $cogs632Materials,'total' => $total632Materials],
            ['tk' => '642', 'name' => 'Chi phí quản lý – CP phát sinh DA',  'rows' => $tk642,           'total' => $total642],
            ['tk' => '641', 'name' => 'Chi phí bán hàng – Hoa hồng',        'rows' => $tk641,           'total' => $total641],
        ];

        $summary = [
            'total_632' => $total632Orders + $total632Materials,
            'total_641' => $total641,
            'total_642' => $total642,
            'grand_total' => $total632Orders + $total632Materials + $total641 + $total642,
        ];

        return Inertia::render('Reports/ExpenseDetail/Index', [
            'groups'      => $groups,
            'summary'     => $summary,
            'filters'     => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'currentYear' => $year,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ExpenseDetailExport($request->all()),
            'expense-detail-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
