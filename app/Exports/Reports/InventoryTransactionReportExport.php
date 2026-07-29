<?php

namespace App\Exports\Reports;

use App\Services\Reports\InventoryTransactionReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// WithStrictNullComparison bắt buộc: PhpSpreadsheet::fromArray() mặc định so sánh
// $cellValue != null (loose) — số 0/0.0 bị coi "bằng null" nên bị bỏ qua, không ghi
// vào cell (dòng tổng hợp sẽ hiện trống thay vì "0"). Strict comparison (===) fix việc này.
class InventoryTransactionReportExport implements FromCollection, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Nhập xuất tồn'; }

    public function headings(): array
    {
        return [
            'Mã hàng hóa', 'Diễn giải',
            'SL tồn đầu kỳ', 'Giá trị tồn đầu kỳ',
            'Số chứng từ nhập', 'Ngày nhập kho', 'SL nhập trong kỳ', 'Giá trị nhập trong kỳ',
            'Số chứng từ xuất', 'Ngày xuất kho', 'SL xuất trong kỳ', 'Giá trị xuất trong kỳ',
            'SL tồn sau CT', 'Giá trị tồn sau CT', 'Trạng thái tồn kho',
        ];
    }

    // Không dùng WithMapping vì mỗi "dòng" Excel thực chất đến từ 1 group nhiều dòng —
    // tự dựng mảng phẳng ở đây để giữ đúng cột mã hàng hóa lặp lại mỗi dòng cho dễ đọc.
    // Cột "SL/Giá trị tồn sau CT" = tồn NGAY SAU dòng đó; chỉ dòng "Cộng phát sinh +
    // Tồn cuối kỳ" mới là số tồn cuối kỳ thực tế — xem mô tả dòng.
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
                    $row['row_type'] === 'closing' ? $group['status']['label'] : '',
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
