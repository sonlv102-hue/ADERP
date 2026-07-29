<?php

namespace App\Exports\Reports;

use App\Services\Reports\InventoryTransactionReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryTransactionReportExport implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Nhập xuất tồn'; }

    public function headings(): array
    {
        return [
            'Mã hàng hóa', 'Diễn giải',
            'SL tồn đầu kỳ', 'Tiền tồn đầu kỳ',
            'Số chứng từ nhập', 'Ngày nhập kho', 'SL nhập trong kỳ', 'Tiền nhập trong kỳ',
            'Số chứng từ xuất', 'Ngày xuất kho', 'SL xuất trong kỳ', 'Tiền xuất trong kỳ',
            'SL tồn cuối kỳ', 'Tiền tồn cuối kỳ',
        ];
    }

    // Không dùng WithMapping vì mỗi "dòng" Excel thực chất đến từ 1 group nhiều dòng —
    // tự dựng mảng phẳng ở đây để giữ đúng cột mã hàng hóa lặp lại mỗi dòng cho dễ đọc.
    public function collection()
    {
        $groups = (new InventoryTransactionReportService())->buildAllProductGroups($this->filters);

        $flat = collect();
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $flat->push([
                    $group['product']['code'],
                    $row['description'],
                    $row['qty_begin'],
                    $row['value_begin'],
                    $row['in_doc_code'],
                    $row['in_doc_date'],
                    $row['qty_in'],
                    $row['value_in'],
                    $row['out_doc_code'],
                    $row['out_doc_date'],
                    $row['qty_out'],
                    $row['value_out'],
                    $row['qty_end'],
                    $row['value_end'],
                ]);
            }
        }

        return $flat;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
