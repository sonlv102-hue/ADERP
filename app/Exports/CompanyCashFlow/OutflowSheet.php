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
 * Sheet 04 — Tiền ra theo danh mục (spec §13). Nhận Collection cùng field shape với
 * CompanyCashFlowReportService::byCategory() (cash_flow_category_id, cashFlowCategory,
 * total_in, total_out, tx_count) — controller truyền categoryBreakdownForExport() để khớp
 * đúng internal-transfer-exclusion của KPI sheet 01 (spec §6), không group lại bằng logic riêng.
 *
 * WithStrictNullComparison: TỔNG CỘNG có thể = 0 khi consolidated loại hết internal transfer
 * (test riêng) — nếu không có interface này, Maatwebsite ghi cell trống thay vì "0" (xem OverviewSheet).
 */
class OutflowSheet implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithEvents, WithStrictNullComparison
{
    private int $lastDataRow = 1;

    public function __construct(private Collection $byCategory)
    {
    }

    public function title(): string
    {
        return '04_Tien_ra';
    }

    public function headings(): array
    {
        return ['STT', 'Mã category', 'Mục đích chi', 'Số giao dịch', 'Tổng tiền', 'Tỷ trọng %'];
    }

    public function columnFormats(): array
    {
        return ['E' => '#,##0', 'F' => '0.00"%"'];
    }

    public function array(): array
    {
        $items = $this->byCategory->filter(fn ($r) => (float) $r->total_out > 0)->values();
        $grandTotal = (float) $items->sum('total_out');

        $rows = [];
        $seq = 1;
        foreach ($items as $r) {
            $totalOut = (float) $r->total_out;
            $rows[] = [
                $seq++,
                $r->cashFlowCategory?->code ?? '',
                $r->cashFlowCategory?->name ?? 'Chưa xác định',
                (int) $r->tx_count,
                $totalOut,
                $grandTotal > 0 ? round($totalOut / $grandTotal * 100, 2) : 0,
            ];
        }

        $rows[] = [
            '', '', 'TỔNG CỘNG',
            $items->sum('tx_count'),
            $grandTotal,
            $grandTotal > 0 ? 100 : 0,
        ];
        $this->lastDataRow = count($rows) + 1;

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:F1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '991B1B']],
                ]);
                $sheet->getStyle("A{$this->lastDataRow}:F{$this->lastDataRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF2F2']],
                ]);
                $sheet->freezePane('A2');
            },
        ];
    }
}
