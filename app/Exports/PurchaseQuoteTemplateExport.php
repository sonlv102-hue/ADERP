<?php

namespace App\Exports;

use App\Imports\PurchaseSupplierQuoteImport;
use App\Models\PurchaseQuoteComparison;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mẫu Excel để NCC điền báo giá. Prefill sẵn danh sách hàng của đợt so sánh.
 *
 *   Dòng 1 = dấu nhận diện template (KHÔNG xóa)
 *   Dòng 2 = tiêu đề cột
 *   Dòng 3+ = danh sách hàng
 *
 * Column order KHỚP với App\Imports\PurchaseSupplierQuoteImport (đọc theo vị trí).
 */
class PurchaseQuoteTemplateExport implements FromArray, WithTitle, WithColumnWidths, WithStyles
{
    public function __construct(private PurchaseQuoteComparison $comparison) {}

    private const HEADERS = [
        'Mã hàng*', 'Tên hàng', 'Quy cách', 'ĐVT*', 'SL yêu cầu*',
        'Đơn giá*', 'CK %', 'VAT %', 'Thời gian giao', 'Bảo hành', 'Ghi chú',
    ];

    public function array(): array
    {
        $marker = [
            PurchaseSupplierQuoteImport::TEMPLATE_MARKER,
            'Version: ' . PurchaseSupplierQuoteImport::TEMPLATE_VERSION,
            '⚠ KHÔNG xóa/sửa 2 dòng đầu. NCC chỉ điền từ dòng 3 trở xuống.',
            '', '', '', '', '', '', '', '',
        ];

        $items = $this->comparison->items()->orderBy('id')->get()->map(fn ($item) => [
            $item->product_code_snapshot,
            $item->product_name_snapshot,
            $item->specification,
            $item->unit_snapshot,
            (float) $item->requested_qty,
            null, null, null, null, null, null,
        ])->toArray();

        return array_merge([$marker, self::HEADERS], $items);
    }

    public function title(): string
    {
        return 'Mau bao gia';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 16, 'B' => 34, 'C' => 18, 'D' => 10, 'E' => 12,
            'F' => 16, 'G' => 8, 'H' => 8, 'I' => 16, 'J' => 16, 'K' => 24,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'italic' => true, 'color' => ['rgb' => '9A3412']]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}
