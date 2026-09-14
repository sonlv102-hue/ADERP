<?php

namespace App\Exports\CompanyCashFlow;

use App\Exports\CompanyCashFlow\Concerns\SanitizesFormulaInjection;
use App\Helpers\PartyTypeLabels;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Sheet 05 — Theo đối tượng (spec §14). Group theo (party_type + party_id), không theo
 * party_name — dữ liệu lấy từ CompanyCashFlowReportService::partiesBreakdown(), tính trên
 * chính tập giao dịch đã dùng cho sheet 02, không phải query riêng.
 *
 * WithStrictNullComparison: một đối tượng chỉ có tiền ra (total_in=0) hoặc chỉ có tiền vào
 * (total_out=0) rất phổ biến — nếu không có interface này, Maatwebsite ghi cell trống thay
 * vì "0", dễ hiểu nhầm thành "chưa có dữ liệu" (xem OverviewSheet).
 */
class PartySheet implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithEvents, WithStrictNullComparison
{
    use SanitizesFormulaInjection;

    private int $lastDataRow = 1;

    public function __construct(private array $parties)
    {
    }

    public function title(): string
    {
        return '05_Doi_tuong';
    }

    public function headings(): array
    {
        return [
            'STT', 'Loại đối tượng', 'Mã/ID', 'Tên đối tượng',
            'Số giao dịch tiền vào', 'Tiền vào', 'Số giao dịch tiền ra', 'Tiền ra', 'Dòng tiền thuần',
        ];
    }

    public function columnFormats(): array
    {
        return ['F' => '#,##0', 'H' => '#,##0', 'I' => '#,##0'];
    }

    public function array(): array
    {
        $rows = [];
        $seq = 1;

        foreach ($this->parties['rows'] as $p) {
            $rows[] = [
                $seq++,
                PartyTypeLabels::label($p['party_type']),
                $p['party_id'],
                $this->safeText($p['party_name']),
                $p['in_count'],
                $p['total_in'],
                $p['out_count'],
                $p['total_out'],
                $p['total_in'] - $p['total_out'],
            ];
        }

        $u = $this->parties['unidentified'];
        if ($u['in_count'] > 0 || $u['out_count'] > 0) {
            $rows[] = [
                $seq++, '', '', 'Chưa xác định đối tượng',
                $u['in_count'], $u['total_in'], $u['out_count'], $u['total_out'],
                $u['total_in'] - $u['total_out'],
            ];
        }

        $rows[] = [
            '', '', '', 'TỔNG CỘNG',
            collect($rows)->sum(fn ($r) => $r[4]),
            collect($rows)->sum(fn ($r) => $r[5]),
            collect($rows)->sum(fn ($r) => $r[6]),
            collect($rows)->sum(fn ($r) => $r[7]),
            collect($rows)->sum(fn ($r) => $r[8]),
        ];
        $this->lastDataRow = count($rows) + 1;

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:I1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                ]);
                $sheet->getStyle("A{$this->lastDataRow}:I{$this->lastDataRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
                ]);
                $sheet->freezePane('A2');
            },
        ];
    }
}
