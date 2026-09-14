<?php

namespace App\Exports;

use App\Models\PurchaseQuoteComparison;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PurchaseQuoteComparisonResultExport implements WithMultipleSheets
{
    public function __construct(
        private PurchaseQuoteComparison $comparison,
        private array $matrix,
        private array $summary,
    ) {}

    public function sheets(): array
    {
        return [
            new PriceComparisonSheet($this->matrix),
            new SupplierQuotesSheet($this->comparison),
            new SelectedSuppliersSheet($this->matrix, $this->summary),
        ];
    }
}

class PriceComparisonSheet implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private array $matrix) {}

    public function title(): string
    {
        return 'Price Comparison';
    }

    public function headings(): array
    {
        $head = ['Mã hàng', 'Tên hàng', 'SL', 'ĐVT'];
        foreach ($this->matrix['suppliers'] as $s) {
            $head[] = $s['name'] . ($s['code'] ? " ({$s['code']})" : '');
        }
        $head[] = 'Giá thấp nhất';
        $head[] = 'NCC giá thấp nhất';
        $head[] = 'NCC lựa chọn';

        return $head;
    }

    public function array(): array
    {
        $supplierIds = array_column($this->matrix['suppliers'], 'supplier_id');
        $nameById = collect($this->matrix['suppliers'])->pluck('name', 'supplier_id');

        $rows = [];
        foreach ($this->matrix['items'] as $item) {
            $row = [
                $item['product_code'],
                $item['product_name'],
                $item['requested_qty'],
                $item['unit'],
            ];
            foreach ($supplierIds as $sid) {
                $cell = $item['cells'][$sid] ?? null;
                $row[] = $cell ? $cell['compare_price'] : '—';
            }
            $row[] = $item['lowest_price'] ?? '—';
            $row[] = $item['lowest_supplier_id'] ? ($nameById[$item['lowest_supplier_id']] ?? '') : '—';
            $row[] = $item['selection'] ? ($nameById[$item['selection']['supplier_id']] ?? '') : '—';
            $rows[] = $row;
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

class SupplierQuotesSheet implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private PurchaseQuoteComparison $comparison) {}

    public function title(): string
    {
        return 'Supplier Quotes';
    }

    public function headings(): array
    {
        return ['NCC', 'Số báo giá', 'Version', 'Ngày báo giá', 'Hiệu lực đến', 'Số mặt hàng báo giá', 'Giá trị chưa VAT', 'VAT', 'Tổng tiền', 'Phí vận chuyển', 'Ghi chú'];
    }

    public function array(): array
    {
        $this->comparison->loadMissing(['activeQuotes.supplier', 'activeQuotes.lines.comparisonItem']);

        return $this->comparison->activeQuotes->map(function ($q) {
            $t = $q->lineTotals();

            return [
                $q->supplier->name ?? '—',
                $q->quote_no,
                $q->version_no,
                optional($q->quote_date)->format('d/m/Y'),
                optional($q->valid_until)->format('d/m/Y'),
                $q->lines->count(),
                $t['subtotal'],
                $t['vat'],
                $t['total'],
                (float) $q->shipping_fee,
                $q->note,
            ];
        })->toArray();
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

class SelectedSuppliersSheet implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private array $matrix, private array $summary) {}

    public function title(): string
    {
        return 'Selected Suppliers';
    }

    public function headings(): array
    {
        return ['NCC', 'Số SKU được chọn', 'Giá trị chưa VAT', 'VAT', 'Tổng thanh toán'];
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->summary['by_supplier'] as $s) {
            $rows[] = [$s['supplier_name'], $s['sku_count'], $s['subtotal'], $s['vat'], $s['total']];
        }

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['Giá trị theo phương án lựa chọn (chưa VAT)', '', $this->summary['selection_subtotal'], '', ''];
        $rows[] = ['Giá trị nếu luôn chọn giá thấp nhất (chưa VAT)', '', $this->summary['lowest_total'], '', ''];
        $rows[] = ['Chênh lệch', '', $this->summary['difference'], '', ''];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
