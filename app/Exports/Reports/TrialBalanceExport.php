<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TrialBalanceExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Cân đối phát sinh'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";
        $prev = date('Y-m-d', strtotime($from . ' -1 day'));

        $accounts = $this->buildAccounts($from, $to, $prev);

        return collect($accounts);
    }

    private function buildAccounts(string $from, string $to, string $prev): array
    {
        // Simplified re-implementation for export; mirrors TrialBalanceController::buildAccounts
        $data = [];

        // TK 112
        $cashIn  = (float) DB::table('payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $cashOut = (float) DB::table('purchase_invoice_payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $data[]  = (object)['code' => '112', 'name' => 'Tiền gửi ngân hàng', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => $cashIn, 'cr' => $cashOut, 'closing_debit' => max(0, $cashIn - $cashOut), 'closing_credit' => max(0, $cashOut - $cashIn)];

        // TK 131
        $arDr = (float) DB::table('invoices')->whereNotIn('status', ['cancelled'])->whereBetween('issue_date', [$from, $to])->sum('total');
        $arCr = (float) DB::table('payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $data[] = (object)['code' => '131', 'name' => 'Phải thu của khách hàng', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => $arDr, 'cr' => $arCr, 'closing_debit' => max(0, $arDr - $arCr), 'closing_credit' => max(0, $arCr - $arDr)];

        // TK 156
        $stIn  = (float) DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')->where('type', 'in')->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stOut = (float) DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')->where('type', 'out')->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $data[] = (object)['code' => '156', 'name' => 'Hàng hóa', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => $stIn, 'cr' => $stOut, 'closing_debit' => max(0, $stIn - $stOut), 'closing_credit' => max(0, $stOut - $stIn)];

        // TK 331
        $apCr = (float) DB::table('purchase_invoices')->whereNotIn('status', ['cancelled'])->whereBetween('invoice_date', [$from, $to])->sum('total');
        $apDr = (float) DB::table('purchase_invoice_payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $data[] = (object)['code' => '331', 'name' => 'Phải trả người bán', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => $apDr, 'cr' => $apCr, 'closing_debit' => max(0, $apDr - $apCr), 'closing_credit' => max(0, $apCr - $apDr)];

        // TK 511
        $rev  = (float) DB::table('invoices')->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('issue_date', [$from, $to])->sum('subtotal');
        $data[] = (object)['code' => '511', 'name' => 'Doanh thu bán hàng', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => 0, 'cr' => $rev, 'closing_debit' => 0, 'closing_credit' => $rev];

        // TK 632
        $cogs = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('products', 'products.id', '=', 'order_items.product_id')->whereBetween('orders.order_date', [$from, $to])->whereNotIn('orders.status', ['draft', 'cancelled'])->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));
        $data[] = (object)['code' => '632', 'name' => 'Giá vốn hàng bán', 'opening_debit' => 0, 'opening_credit' => 0, 'dr' => $cogs, 'cr' => 0, 'closing_debit' => $cogs, 'closing_credit' => 0];

        return $data;
    }

    public function headings(): array
    {
        return ['TK', 'Tên tài khoản', 'Dư đầu kỳ Nợ', 'Dư đầu kỳ Có', 'PS Nợ', 'PS Có', 'Dư cuối kỳ Nợ', 'Dư cuối kỳ Có'];
    }

    public function map($row): array
    {
        return [$row->code, $row->name, $row->opening_debit, $row->opening_credit, $row->dr, $row->cr, $row->closing_debit, $row->closing_credit];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
