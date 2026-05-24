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

class IncomeStatementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Kết quả HĐKD'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";

        $revenue  = (float) DB::table('invoices')->whereNotIn('status', ['draft'])->whereBetween('issue_date', [$from, $to])->sum('subtotal');
        $cogsOrders = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('products', 'products.id', '=', 'order_items.product_id')->whereBetween('orders.order_date', [$from, $to])->whereNotIn('orders.status', ['draft', 'cancelled'])->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));
        $cogsMaterials = (float) DB::table('project_materials')->join('projects', 'projects.id', '=', 'project_materials.project_id')->whereBetween('projects.start_date', [$from, $to])->whereNotIn('projects.status', ['cancelled'])->sum(DB::raw('project_materials.quantity * project_materials.unit_price'));
        $cogsExpenses = (float) DB::table('project_expenses')->join('projects', 'projects.id', '=', 'project_expenses.project_id')->whereBetween('projects.start_date', [$from, $to])->whereNotIn('projects.status', ['cancelled'])->sum('project_expenses.amount');
        $cogs = $cogsOrders + $cogsMaterials + $cogsExpenses;
        $grossProfit = $revenue - $cogs;

        return collect([
            (object)['label' => 'Doanh thu bán hàng và CCDV', 'amount' => $revenue],
            (object)['label' => 'Giá vốn hàng bán',           'amount' => -$cogs],
            (object)['label' => '  - Từ đơn hàng',             'amount' => -$cogsOrders],
            (object)['label' => '  - Vật tư dự án',            'amount' => -$cogsMaterials],
            (object)['label' => '  - Chi phí phát sinh',       'amount' => -$cogsExpenses],
            (object)['label' => 'Lợi nhuận gộp',              'amount' => $grossProfit],
            (object)['label' => 'Lợi nhuận thuần từ HĐKD',    'amount' => $grossProfit],
            (object)['label' => 'Lợi nhuận trước thuế',       'amount' => $grossProfit],
            (object)['label' => 'Lợi nhuận sau thuế',         'amount' => $grossProfit],
        ]);
    }

    public function headings(): array { return ['Chỉ tiêu', 'Giá trị (VND)']; }
    public function map($row): array  { return [$row->label, $row->amount]; }
    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
