<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderOverDelivery;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Project;
use App\Models\InventoryBalance;
use App\Models\Ticket;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        return Inertia::render('Dashboard/Index', [
            'stats'              => Cache::remember('dashboard.stats', 600, fn () => $this->stats()),
            'revenueChart'       => Cache::remember('dashboard.revenue_chart', 600, fn () => $this->revenueChart()),
            'topCustomers'       => Cache::remember('dashboard.top_customers', 600, fn () => $this->topCustomers()),
            'stockOverview'      => Cache::remember('dashboard.stock_overview', 120, fn () => $this->stockOverview()),
            'ticketStats'        => Cache::remember('dashboard.ticket_stats', 300, fn () => $this->ticketStats()),
            'unfulfilledOrders'  => Cache::remember('dashboard.unfulfilled_orders', 120, fn () => $this->unfulfilledOrders()),
            'overDeliveryAlerts' => $this->overDeliveryAlerts(),
            'accountingAlerts'   => Cache::remember('dashboard.accounting_alerts', 300, fn () => $this->accountingAlerts()),
            'financialKpi'       => $user->can('accounting.view')
                ? Cache::remember('dashboard.financial_kpi.' . now()->format('Y-m'), 300, fn () => $this->financialKpi())
                : null,
        ]);
    }

    private function stats(): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // Thống kê đơn mua hàng trong tháng
        $purchaseOrdersCount = PurchaseOrder::where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$startOfMonth, $endOfMonth])
            ->count();

        $purchaseOrdersTotal = (float) PurchaseOrderItem::whereHas('purchaseOrder', function ($q) use ($startOfMonth, $endOfMonth) {
            $q->where('status', '!=', 'cancelled')->whereBetween('order_date', [$startOfMonth, $endOfMonth]);
        })->selectRaw('SUM(quantity * unit_price * (1 + COALESCE(vat_rate, 0) / 100.0)) as total')->value('total');

        // Thống kê đơn bán hàng trong tháng
        $salesOrdersCount = Order::where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$startOfMonth, $endOfMonth])
            ->count();

        $salesOrdersTotal = (float) OrderItem::whereHas('order', function ($q) use ($startOfMonth, $endOfMonth) {
            $q->where('status', '!=', 'cancelled')->whereBetween('order_date', [$startOfMonth, $endOfMonth]);
        })->selectRaw('SUM((quantity * unit_price - COALESCE(discount_amount, 0)) + ROUND((quantity * unit_price - COALESCE(discount_amount, 0)) * COALESCE(vat_rate, 0) / 100)) as total')->value('total');

        return [
            'total_customers'  => Customer::count(),
            'total_products'   => Product::where('is_active', true)->count(),
            'open_tickets'     => Ticket::whereIn('status', ['open', 'in_progress'])->count(),
            'active_projects'  => Project::whereIn('status', ['planning', 'in_progress'])->count(),
            'purchase_orders_count' => $purchaseOrdersCount,
            'purchase_orders_total' => $purchaseOrdersTotal,
            'sales_orders_count'    => $salesOrdersCount,
            'sales_orders_total'    => $salesOrdersTotal,
        ];
    }

    private function revenueChart(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite'
            ? "strftime('%m/%Y', payment_date)"
            : "TO_CHAR(payment_date, 'MM/YYYY')";

        $payments = Payment::where('payment_date', '>=', $startDate)
            ->selectRaw("{$dateExpr} as month, SUM(amount) as amount")
            ->groupByRaw($dateExpr)
            ->pluck('amount', 'month');

        return collect(range(11, 0))->map(function ($i) use ($payments) {
            $date = now()->subMonths($i);
            $key  = $date->format('m/Y');
            return ['month' => $key, 'amount' => (float) ($payments[$key] ?? 0)];
        })->values()->all();
    }

    private function topCustomers(): array
    {
        return Payment::select('customers.id', 'customers.name', DB::raw('SUM(payments.amount) as total'))
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->join('customers', 'invoices.customer_id', '=', 'customers.id')
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (float) $r->total])
            ->all();
    }

    private function stockOverview(): array
    {
        $products = Product::where('is_active', true)
            ->get(['id', 'code', 'name', 'unit', 'min_stock']);

        if ($products->isEmpty()) return [];

        $stocks = InventoryBalance::stockForProducts($products->pluck('id'));

        return $products
            ->map(fn ($p) => [
                'id'        => $p->id,
                'code'      => $p->code,
                'name'      => $p->name,
                'unit'      => $p->unit,
                'stock'     => (int) ($stocks[$p->id] ?? 0),
                'min_stock' => (int) $p->min_stock,
            ])
            ->sortBy(fn ($p) => [
                // Ưu tiên: dưới mức tối thiểu (0) lên trước (1), rồi sắp xếp theo tồn kho tăng dần
                $p['min_stock'] > 0 && $p['stock'] <= $p['min_stock'] ? 0 : 1,
                $p['stock'],
            ])
            ->values()
            ->all();
    }

    private function unfulfilledOrders(): array
    {
        // Chỉ lấy đơn hàng trong 90 ngày gần nhất để tránh OOM khi data lớn
        $since = now()->subDays(90)->startOfDay();

        $orders = Order::with(['customer', 'items'])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('order_date', '>=', $since)
            ->latest('order_date')
            ->limit(200)
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $productIds = $orders->flatMap->items
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique();

        $stocks = InventoryBalance::stockForProducts($productIds);

        $result = [];
        foreach ($orders as $order) {
            $undeliveredItems = [];
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $remaining = max(0, (float) $item->quantity - (float) $item->delivered_quantity);
                if ($remaining <= 0) {
                    continue; // đã giao đủ → bỏ qua
                }
                $stock    = (int) ($stocks[$item->product_id] ?? 0);
                $shortage = max(0, (int) $remaining - $stock);
                $undeliveredItems[] = [
                    'product_name' => $item->name,
                    'remaining'    => (int) $remaining,
                    'stock'        => $stock,
                    'shortage'     => $shortage, // 0 = đủ hàng nhưng chưa xuất, >0 = thiếu hàng
                ];
            }
            if (! empty($undeliveredItems)) {
                $result[] = [
                    'id'           => $order->id,
                    'code'         => $order->code,
                    'customer'     => $order->customer?->name,
                    'status'       => $order->status->value,
                    'status_label' => $order->status->label(),
                    'status_color' => $order->status->color(),
                    'items'        => $undeliveredItems,
                ];
            }
        }

        return array_slice($result, 0, 10);
    }

    private function overDeliveryAlerts(): array
    {
        $alerts = OrderOverDelivery::whereNull('resolved_at')
            ->with(['order.customer'])
            ->orderByDesc('created_at')
            ->get();

        $alertOrderIds = $alerts->pluck('order_id')->unique();

        $pendingSupplementaries = Order::whereIn('supplementary_for_order_id', $alertOrderIds)
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
            ->get(['id', 'code', 'supplementary_for_order_id']);

        $contracts = Contract::whereIn('order_id', $alertOrderIds)
            ->get(['id', 'code', 'order_id']);

        return $alerts
            ->groupBy('order_id')
            ->map(function ($group) use ($pendingSupplementaries, $contracts) {
                $orderId  = $group->first()->order_id;
                $pending  = $pendingSupplementaries->firstWhere('supplementary_for_order_id', $orderId);
                $contract = $contracts->firstWhere('order_id', $orderId);
                return [
                    'order_id'              => $orderId,
                    'order_code'            => $group->first()->order->code,
                    'customer'              => $group->first()->order->customer?->name,
                    'customer_id'           => $group->first()->order->customer_id,
                    'pending_supplementary' => $pending  ? ['id' => $pending->id,  'code' => $pending->code]  : null,
                    'contract'              => $contract ? ['id' => $contract->id, 'code' => $contract->code] : null,
                    'products'              => $group->map(fn ($a) => [
                        'name'          => $a->product_name,
                        'over_quantity' => (float) $a->over_quantity,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function ticketStats(): array
    {
        $counts = Ticket::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return collect(TicketStatus::cases())->map(fn ($s) => [
            'status' => $s->value,
            'label'  => $s->label(),
            'color'  => $s->color(),
            'count'  => (int) ($counts[$s->value] ?? 0),
        ])->all();
    }

    private function financialKpi(): array
    {
        $now  = now();
        $curr = ['from' => $now->copy()->startOfMonth()->toDateString(), 'to' => $now->copy()->endOfMonth()->toDateString()];
        $prev = ['from' => $now->copy()->subMonth()->startOfMonth()->toDateString(), 'to' => $now->copy()->subMonth()->endOfMonth()->toDateString()];

        $fetch = function (string $from, string $to): array {
            $rows = DB::table('journal_entry_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')
                ->where('je.exclude_from_period_movement', false)
                ->whereBetween('je.entry_date', [$from, $to])
                ->whereRaw("(jl.account_code LIKE '511%' OR jl.account_code LIKE '632%')")
                ->selectRaw("
                    jl.account_code,
                    SUM(jl.debit)  AS total_debit,
                    SUM(jl.credit) AS total_credit
                ")
                ->groupBy('jl.account_code')
                ->get();

            $revenue = 0.0;
            $cogs    = 0.0;

            foreach ($rows as $row) {
                if (str_starts_with($row->account_code, '511')) {
                    // Revenue = net credit (credit-normal account)
                    $revenue += (float) $row->total_credit - (float) $row->total_debit;
                } elseif (str_starts_with($row->account_code, '632')) {
                    // COGS = net debit (debit-normal account)
                    $cogs += (float) $row->total_debit - (float) $row->total_credit;
                }
            }

            return [
                'revenue'       => $revenue,
                'cogs'          => $cogs,
                'gross_profit'  => $revenue - $cogs,
            ];
        };

        $current  = $fetch($curr['from'], $curr['to']);
        $previous = $fetch($prev['from'], $prev['to']);

        return [
            'current'       => $current,
            'previous'      => $previous,
            'period_label'  => $now->translatedFormat('m/Y'),
            'prev_label'    => $now->copy()->subMonth()->translatedFormat('m/Y'),
            'date_from'     => $curr['from'],
            'date_to'       => $curr['to'],
        ];
    }

    private function accountingAlerts(): array
    {
        $today = now()->toDateString();

        // HĐ đã gửi + quá due_date nhưng chưa được đánh dấu Overdue
        $pendingOverdue = Invoice::where('status', 'sent')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->count();

        // HĐ đang ở trạng thái Quá hạn
        $overdueInvoices = Invoice::where('status', 'overdue')->count();

        // Giao dịch ngân hàng chưa đối chiếu
        $unreconciledBank = BankTransaction::where('status', 'pending')->count();

        // Bảng lương chưa xác nhận (draft/pending)
        $pendingPayrolls = Payroll::whereNotIn('status', ['confirmed', 'paid'])->count();

        // Tổng giá trị HĐ quá hạn
        $overdueAmount = Invoice::where('status', 'overdue')
            ->get()
            ->sum(fn ($inv) => max(0, (float) $inv->total - $inv->amountPaid()));

        return [
            'pending_overdue_invoices'  => $pendingOverdue,
            'overdue_invoices'          => $overdueInvoices,
            'overdue_amount'            => $overdueAmount,
            'unreconciled_bank'         => $unreconciledBank,
            'pending_payrolls'          => $pendingPayrolls,
            'has_alerts'                => ($pendingOverdue + $overdueInvoices + $unreconciledBank + $pendingPayrolls) > 0,
        ];
    }
}
