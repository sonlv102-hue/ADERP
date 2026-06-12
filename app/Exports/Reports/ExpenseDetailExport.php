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

class ExpenseDetailExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Chi tiết chi phí'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";

        $rows = [];

        foreach (DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.order_date', [$from, $to])
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->select('orders.order_date as date', 'orders.code as ref', 'products.name as description',
                     DB::raw('order_items.quantity * COALESCE(products.cost_price, 0) as amount'))
            ->get() as $r) {
            $rows[] = (object)['date' => $r->date, 'ref' => $r->ref, 'tk' => '632', 'description' => 'Giá vốn ĐH: ' . $r->description, 'amount' => (float)$r->amount];
        }

        foreach (DB::table('project_materials')
            ->join('projects', 'projects.id', '=', 'project_materials.project_id')
            ->join('products', 'products.id', '=', 'project_materials.product_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->select('projects.start_date as date', 'projects.code as ref', 'products.name as description',
                     DB::raw('project_materials.quantity * project_materials.unit_price as amount'))
            ->get() as $r) {
            $rows[] = (object)['date' => $r->date, 'ref' => $r->ref, 'tk' => '632', 'description' => 'Vật tư DA: ' . $r->description, 'amount' => (float)$r->amount];
        }

        foreach (DB::table('project_expenses')
            ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->select('projects.start_date as date', 'projects.code as ref',
                     'project_expenses.description as description', 'project_expenses.amount')
            ->get() as $r) {
            $rows[] = (object)['date' => $r->date, 'ref' => $r->ref, 'tk' => '642', 'description' => 'CP DA: ' . $r->description, 'amount' => (float)$r->amount];
        }

        foreach (DB::table('commissions')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select(DB::raw('DATE(created_at) as date'), 'code as ref', 'description', 'amount')
            ->get() as $r) {
            $rows[] = (object)['date' => $r->date, 'ref' => $r->ref, 'tk' => '641', 'description' => 'Hoa hồng: ' . ($r->description ?: $r->ref), 'amount' => (float)$r->amount];
        }

        usort($rows, fn($a, $b) => strcmp($a->date, $b->date));

        return collect($rows);
    }

    public function headings(): array { return ['Ngày', 'Chứng từ', 'TK', 'Diễn giải', 'Số tiền']; }
    public function map($row): array  { return [$row->date, $row->ref, $row->tk, $row->description, $row->amount]; }
    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
