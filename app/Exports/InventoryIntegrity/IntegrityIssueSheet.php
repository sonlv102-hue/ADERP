<?php

namespace App\Exports\InventoryIntegrity;

use App\Exports\CompanyCashFlow\Concerns\SanitizesFormulaInjection;
use App\Services\Inventory\Integrity\IntegrityReportQuery;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Giá trị chưa xác định để TRỐNG (không ghi 0). */
class IntegrityIssueSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStrictNullComparison
{
    use SanitizesFormulaInjection;

    public function __construct(private string $title, private Collection $issues) {}

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function headings(): array
    {
        return ['Mã vấn đề', 'Quy tắc', 'Phân loại', 'Mức độ', 'Trạng thái', 'Nhóm phụ trách', 'Product ID', 'SKU', 'Tên sản phẩm', 'Kho', 'Dự án',
            'Chứng từ', 'Tồn sổ kho', 'Tồn AVCO', 'Tồn lô DA', 'Tồn khả dụng', 'Chênh SL', 'GT sổ kho', 'GT đối chiếu', 'Chênh GT', 'Cơ sở đối chiếu',
            'Legacy', 'Nguyên nhân', 'Phương án', 'Phương án (kế toán)', 'Người phụ trách', 'Hạn', 'Phát hiện', 'Kiểm tra gần nhất', 'Mở lại'];
    }

    public function array(): array
    {
        return $this->issues->map(fn ($i) => [
            'VD-' . $i->id, $i->rule_code, $i->category, $i->severity, $i->status, IntegrityReportQuery::bucket($i), $i->product_id,
            $this->safeText($i->product?->code), $this->safeText($i->product?->name), $i->warehouse?->name, $i->project?->code,
            $this->safeText($i->document_codes), $this->num($i->qty_book), $this->num($i->qty_avco), $this->num($i->qty_lot), $this->num($i->qty_available),
            $this->num($i->qty_diff), $this->num($i->value_book), $this->num($i->value_compare), $this->num($i->value_diff), $i->value_basis,
            $i->is_legacy ? 'Có' : '', $this->safeText($i->cause), $this->safeText($i->proposal), $this->safeText($i->case_info['summary'] ?? null),
            $i->assignee?->name, $i->due_date?->format('d/m/Y'), $i->detected_at?->format('d/m/Y H:i'), $i->last_checked_at?->format('d/m/Y H:i'), $i->reopen_count,
        ])->all();
    }

    private function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
