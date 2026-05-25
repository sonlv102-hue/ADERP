<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderOverDelivery;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard/Index', [
            'stats'              => Cache::remember('dashboard.stats', 600, fn () => $this->stats()),
            'revenueChart'       => Cache::remember('dashboard.revenue_chart', 600, fn () => $this->revenueChart()),
            'topCustomers'       => Cache::remember('dashboard.top_customers', 600, fn () => $this->topCustomers()),
            'stockOverview'      => Cache::remember('dashboard.stock_overview', 120, fn () => $this->stockOverview()),
            'ticketStats'        => Cache::remember('dashboard.ticket_stats', 300, fn () => $this->ticketStats()),
            'unfulfilledOrders'    => Cache::remember('dashboard.unfulfilled_orders', 120, fn () => $this->unfulfilledOrders()),
            'overDeliveryAlerts'   => $this->overDeliveryAlerts(),
        ]);
    }

    private function stats(): array
    {
        return [
            'total_customers'  => Customer::count(),
            'total_products'   => Product::where('is_active', true)->count(),
            'open_tickets'     => Ticket::whereIn('status', ['open', 'in_progress'])->count(),
            'active_projects'  => Project::whereIn('status', ['planning', 'in_progress'])->count(),
        ];
    }

    private function revenueChart(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();

        // 1 query GROUP BY thay vì 12 queries riêng lẻ
        $payments = Payment::where('payment_date', '>=', $startDate)
            ->selectRaw("TO_CHAR(payment_date, 'MM/YYYY') as month, SUM(amount) as amount")
            ->groupByRaw("TO_CHAR(payment_date, 'MM/YYYY')")
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

        $stocks = StockMovement::whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

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
        $orders = Order::with(['customer', 'items'])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $productIds = $orders->flatMap->items
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique();

        $stocks = StockMovement::whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

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
}
