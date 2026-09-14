<?php

namespace App\Exports\CompanyCashFlow;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Sheet 03 — Tiền vào theo danh mục (spec §12). Nhận Collection cùng field shape với
 * CompanyCashFlowReportService::byCategory() (cash_flow_category_id, cashFlowCategory,
 * total_in, total_out, tx_count) — controller truyền categoryBreakdownForExport() để khớp
 * đúng internal-transfer-exclusion của KPI sheet 01 (spec §6), không group lại bằng logic riêng.
 *
 * WithStrictNullComparison: "Tỷ trọng %" của category đóng góp rất nhỏ có thể round về 0.00
 * — nếu không có interface này, Maatwebsite ghi cell trống thay vì "0.00" (xem OverviewSheet).
 */
class InflowSheet implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithEvents, WithStrictNullComparison
{
    private int $lastDataRow = 1;

    public function __construct(private Collection $byCategory)
    {
    }

    public function title(): string
    {
        return '03_Tien_vao';
    }

    public function headings(): array
    {
        return ['STT', 'Mã category', 'Nguồn tiền', 'Số giao dịch', 'Tổng tiền', 'Tỷ trọng %'];
    }

    public function columnFormats(): array
    {
        return ['E' => '#,##0', 'F' => '0.00"%"'];
    }

    public function array(): array
    {
        $items = $this->byCategory->filter(fn ($r) => (float) $r->total_in > 0)->values();
        $grandTotal = (float) $items->sum('total_in');

        $rows = [];
        $seq = 1;
        foreach ($items as $r) {
            $totalIn = (float) $r->total_in;
            $rows[] = [
                $seq++,
                $r->cashFlowCategory?->code ?? '',
                $r->cashFlowCategory?->name ?? 'Chưa xác định',
                (int) $r->tx_count,
                $totalIn,
                $grandTotal > 0 ? round($totalIn / $grandTotal * 100, 2) : 0,
            ];
        }

        $rows[] = [
            '', '', 'TỔNG CỘNG',
            $items->sum('tx_count'),
            $grandTotal,
            $grandTotal > 0 ? 100 : 0,
        ];
        $this->lastDataRow = count($rows) + 1; // +1 vì headings chiếm row 1

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:F1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']],
                ]);
                $sheet->getStyle("A{$this->lastDataRow}:F{$this->lastDataRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDF4']],
                ]);
                $sheet->freezePane('A2');
            },
        ];
    }
}
