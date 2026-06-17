<?php

namespace App\Exports\Reports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Báo cáo tồn kho'; }

    public function collection()
    {
        $search      = $this->filters['search']       ?? null;
        $dateFrom    = $this->filters['date_from']    ?? now()->startOfYear()->toDateString();
        $dateTo      = $this->filters['date_to']      ?? now()->toDateString();
        $warehouseId = $this->filters['warehouse_id'] ?? null;
        $categoryId  = $this->filters['category_id'] ?? null;

        $wh      = $warehouseId ? " AND sm.warehouse_id = {$warehouseId}" : "";
        $joins   = "LEFT JOIN stock_entries se ON sm.source_id = se.id AND sm.source_type = 'App\Models\StockEntry'
                    LEFT JOIN stock_exits sx ON sm.source_id = sx.id AND sm.source_type = 'App\Models\StockExit'";
        $docDate = "COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))";

        return DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->select([
                'products.code', 'products.name', 'products.unit', 'products.cost_price',
                'product_categories.name as category',
                DB::raw("COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm {$joins} WHERE sm.product_id = products.id AND {$docDate} < '{$dateFrom}'{$wh}), 0) as stock_begin"),
                DB::raw("COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm {$joins} WHERE sm.product_id = products.id AND sm.quantity > 0 AND {$docDate} BETWEEN '{$dateFrom}' AND '{$dateTo}'{$wh}), 0) as stock_in"),
                DB::raw("COALESCE((SELECT ABS(SUM(sm.quantity)) FROM stock_movements sm {$joins} WHERE sm.product_id = products.id AND sm.quantity < 0 AND {$docDate} BETWEEN '{$dateFrom}' AND '{$dateTo}'{$wh}), 0) as stock_out"),
                DB::raw("(SELECT MAX({$docDate}) FROM stock_movements sm {$joins} WHERE sm.product_id = products.id AND sm.quantity > 0 AND {$docDate} BETWEEN '{$dateFrom}' AND '{$dateTo}'{$wh}) as last_in_date"),
                DB::raw("(SELECT MAX({$docDate}) FROM stock_movements sm {$joins} WHERE sm.product_id = products.id AND sm.quantity < 0 AND {$docDate} BETWEEN '{$dateFrom}' AND '{$dateTo}'{$wh}) as last_out_date"),
            ])
            ->whereNull('products.deleted_at')
            ->when($search, fn ($q) => $q->where(fn ($q2) => $q2->where('products.code', 'ilike', "%{$search}%")->orWhere('products.name', 'ilike', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->orderBy('products.code')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Mã SP', 'Tên SP', 'ĐVT', 'Danh mục',
            'Tồn đầu kỳ (SL)', 'Giá trị đầu kỳ',
            'Nhập (SL)', 'Ngày nhập g.nhất', 'TT nhập',
            'Xuất (SL)', 'Ngày xuất g.nhất', 'TT xuất',
            'Tồn cuối kỳ (SL)', 'Giá trị tồn cuối',
        ];
    }

    public function map($row): array
    {
        $begin = (float) $row->stock_begin;
        $in    = (float) $row->stock_in;
        $out   = (float) $row->stock_out;
        $end   = $begin + $in - $out;
        $cost  = (float) $row->cost_price;

        return [
            $row->code, $row->name, $row->unit, $row->category ?? '—',
            $begin, $begin * $cost,
            $in, $row->last_in_date ?? '', $in * $cost,
            $out, $row->last_out_date ?? '', $out * $cost,
            $end, $end * $cost,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
