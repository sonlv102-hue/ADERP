<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfitController extends Controller
{
    public function orders(Request $request): Response
    {
        $search     = $request->input('search');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');

        $query = DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->select([
                'orders.id',
                'orders.code',
                'orders.order_date',
                'orders.status',
                'customers.name as customer',
                // Doanh thu = tổng subtotal hóa đơn gắn với đơn hàng
                DB::raw('COALESCE((SELECT SUM(i.subtotal) FROM invoices i WHERE i.order_id = orders.id), 0) as revenue'),
                // Giá vốn = tổng SL × giá vốn sản phẩm (chỉ product items)
                DB::raw('COALESCE((SELECT SUM(oi.quantity * COALESCE(p.cost_price, 0))
                          FROM order_items oi
                          LEFT JOIN products p ON p.id = oi.product_id
                          WHERE oi.order_id = orders.id), 0) as cogs'),
                // Hoa hồng đã duyệt gắn với đơn hàng
                DB::raw("COALESCE((SELECT SUM(c.amount) FROM commissions c
                          WHERE c.order_id = orders.id
                          AND c.status IN ('pending_payment','paid')), 0) as commission"),
                // Giá trị đơn hàng (revenue theo unit_price — không dùng để tính lãi)
                DB::raw('COALESCE((SELECT SUM(oi.quantity * oi.unit_price)
                          FROM order_items oi WHERE oi.order_id = orders.id), 0) as order_value'),
            ])
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('orders.code', 'ilike', "%{$search}%")
                       ->orWhere('customers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('orders.order_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('orders.order_date', '<=', $dateTo))
            ->orderByDesc('orders.id');

        $rows = $query->paginate(25);

        $rows->through(function ($row) {
            $revenue    = (float) $row->revenue;
            $cogs       = (float) $row->cogs;
            $commission = (float) $row->commission;
            $profit     = $revenue - $cogs - $commission;
            $margin     = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

            return [
                'id'           => $row->id,
                'code'         => $row->code,
                'order_date'   => $row->order_date,
                'status'       => $row->status,
                'customer'     => $row->customer,
                'order_value'  => $row->order_value,
                'revenue'      => $revenue,
                'cogs'         => $cogs,
                'commission'   => $commission,
                'profit'       => $profit,
                'margin'       => $margin,
            ];
        });

        // Tổng kết trang hiện tại
        $summary = [
            'total_revenue'    => $rows->sum('revenue'),
            'total_cogs'       => $rows->sum('cogs'),
            'total_commission' => $rows->sum('commission'),
            'total_profit'     => $rows->sum('profit'),
        ];

        return Inertia::render('Reports/Profit/Orders', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['search', 'date_from', 'date_to']),
        ]);
    }

    public function projects(Request $request): Response
    {
        $search   = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        $query = DB::table('projects')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->select([
                'projects.id',
                'projects.code',
                'projects.name',
                'projects.status',
                'projects.budget',
                'projects.contract_id',
                'projects.start_date',
                'customers.name as customer',
                // Doanh thu = hóa đơn gắn với hợp đồng của dự án
                DB::raw('COALESCE((SELECT SUM(i.subtotal) FROM invoices i
                          WHERE i.contract_id = projects.contract_id
                          AND projects.contract_id IS NOT NULL), 0) as revenue'),
                // Chi phí vật tư dự án
                DB::raw('COALESCE((SELECT SUM(pm.quantity * pm.unit_price)
                          FROM project_materials pm
                          WHERE pm.project_id = projects.id), 0) as material_cost'),
                // Chi phí phát sinh (nhân công, thuê ngoài, v.v.)
                DB::raw('COALESCE((SELECT SUM(pe.amount)
                          FROM project_expenses pe
                          WHERE pe.project_id = projects.id), 0) as expense_cost'),
                // Hoa hồng đã duyệt gắn với dự án
                DB::raw("COALESCE((SELECT SUM(c.amount) FROM commissions c
                          WHERE c.project_id = projects.id
                          AND c.status IN ('pending_payment','paid')), 0) as commission"),
            ])
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('projects.code', 'ilike', "%{$search}%")
                       ->orWhere('projects.name', 'ilike', "%{$search}%")
                       ->orWhere('customers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('projects.start_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('projects.start_date', '<=', $dateTo))
            ->orderByDesc('projects.id');

        $rows = $query->paginate(25);

        $rows->through(function ($row) {
            $revenue      = (float) $row->revenue;
            $materialCost = (float) $row->material_cost;
            $expenseCost  = (float) $row->expense_cost;
            $commission   = (float) $row->commission;
            $totalCost    = $materialCost + $expenseCost + $commission;
            $profit       = $revenue - $totalCost;
            $margin       = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

            return [
                'id'            => $row->id,
                'code'          => $row->code,
                'name'          => $row->name,
                'status'        => $row->status,
                'budget'        => $row->budget,
                'start_date'    => $row->start_date,
                'customer'      => $row->customer,
                'revenue'       => $revenue,
                'material_cost' => $materialCost,
                'expense_cost'  => $expenseCost,
                'commission'    => $commission,
                'total_cost'    => $totalCost,
                'profit'        => $profit,
                'margin'        => $margin,
            ];
        });

        $summary = [
            'total_revenue'  => $rows->sum('revenue'),
            'total_cost'     => $rows->sum('total_cost'),
            'total_profit'   => $rows->sum('profit'),
        ];

        return Inertia::render('Reports/Profit/Projects', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['search', 'date_from', 'date_to']),
        ]);
    }
}
