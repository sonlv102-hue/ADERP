<?php

namespace App\Exports;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Base class for all list-type Excel exports.
 *
 * Subclass must implement:
 *   - title()          : string  — sheet/file title
 *   - reportTitle()    : string  — header title shown in Excel
 *   - columns()        : array   — [['key'=>..., 'header'=>..., 'width'=>..., 'type'=>'text|money|number|date']]
 *   - buildRows()      : array   — array of assoc arrays, keys matching columns[*]['key']
 *   - filterDescription(): string — human-readable filter description
 *
 * Optional:
 *   - buildTotals()    : array   — ['column_key' => value, ...] for totals row
 */
abstract class BaseListExport implements FromArray, WithStyles, WithTitle
{
    private const INFO_ROWS    = 5;
    private const BLANK_ROW    = 1;
    private const COL_HDR_ROW  = 7; // INFO_ROWS + BLANK_ROW + 1

    private bool $hasTotals    = false;

    abstract public function title(): string;
    abstract protected function reportTitle(): string;
    abstract protected function columns(): array;
    abstract protected function buildRows(): array;
    abstract protected function filterDescription(): string;

    protected function buildTotals(): array
    {
        return [];
    }

    public function array(): array
    {
        $company  = Setting::get('company_name', 'Công ty TNHH Công nghệ Mini ERP');
        $address  = Setting::get('company_address', '');
        $exporter = auth()->user()?->name ?? '';
        $now      = Carbon::now()->format('d/m/Y H:i');

        $output   = [];
        $output[] = [$company];
        $output[] = [$address];
        $output[] = [$this->reportTitle()];
        $output[] = [$this->filterDescription()];
        $output[] = ["Ngày xuất: {$now}   |   Người xuất: {$exporter}"];
        $output[] = [];

        // Column header row
        $output[] = array_column($this->columns(), 'header');

        // Data rows
        $stt = 1;
        foreach ($this->buildRows() as $row) {
            $line = [];
            foreach ($this->columns() as $col) {
                $line[] = $col['key'] === '__stt' ? $stt++ : ($row[$col['key']] ?? '');
            }
            $output[] = $line;
        }

        // Totals row
        $totals = $this->buildTotals();
        $this->hasTotals = !empty($totals);
        if ($this->hasTotals) {
            $totalLine = [];
            foreach ($this->columns() as $col) {
                $totalLine[] = $totals[$col['key']] ?? '';
            }
            $output[] = $totalLine;
        }

        return $output;
    }

    public function styles(Worksheet $sheet): array
    {
        $cols      = $this->columns();
        $colCount  = count($cols);
        $lastLetter = $this->colLetter($colCount);
        $dataStart  = self::COL_HDR_ROW + 1;
        $highRow    = $sheet->getHighestRow();

        // Column widths
        foreach ($cols as $i => $col) {
            $sheet->getColumnDimension($this->colLetter($i + 1))
                  ->setWidth($col['width'] ?? 16);
        }

        // Merge info rows across all columns
        if ($colCount > 1) {
            foreach (range(1, self::INFO_ROWS) as $r) {
                $sheet->mergeCells("A{$r}:{$lastLetter}{$r}");
            }
            $sheet->mergeCells("A6:{$lastLetter}6");
        }

        // Company name style
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A2')->getFont()->setSize(10);

        // Report title
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A3')->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Filter + export info
        $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A5')->getFont()->setItalic(true)->setSize(9);

        // Column header row
        $hdrRange = "A" . self::COL_HDR_ROW . ":{$lastLetter}" . self::COL_HDR_ROW;
        $sheet->getStyle($hdrRange)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 10,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '3B5BDB'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'FFFFFF'],
                ],
            ],
        ]);
        $sheet->getRowDimension(self::COL_HDR_ROW)->setRowHeight(24);

        // Data range
        if ($highRow >= $dataStart) {
            $dataRange = "A{$dataStart}:{$lastLetter}{$highRow}";

            // Alternating row colors + thin borders
            for ($r = $dataStart; $r <= $highRow; $r++) {
                $rangeLine = "A{$r}:{$lastLetter}{$r}";
                if (($r - $dataStart) % 2 === 1) {
                    $sheet->getStyle($rangeLine)->getFill()
                          ->setFillType(Fill::FILL_SOLID)
                          ->getStartColor()->setRGB('F8F9FA');
                }
            }
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color'       => ['rgb' => 'DEE2E6'],
                    ],
                ],
            ]);

            // Freeze header
            $sheet->freezePane("A{$dataStart}");

            // Money format
            foreach ($cols as $i => $col) {
                if (($col['type'] ?? '') === 'money') {
                    $letter = $this->colLetter($i + 1);
                    $sheet->getStyle("{$letter}{$dataStart}:{$letter}{$highRow}")
                          ->getNumberFormat()
                          ->setFormatCode('#,##0');
                    $sheet->getStyle("{$letter}{$dataStart}:{$letter}{$highRow}")
                          ->getAlignment()
                          ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                if (($col['type'] ?? '') === 'number') {
                    $letter = $this->colLetter($i + 1);
                    $sheet->getStyle("{$letter}{$dataStart}:{$letter}{$highRow}")
                          ->getAlignment()
                          ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
            }

            // Totals row bold
            if ($this->hasTotals) {
                $sheet->getStyle("A{$highRow}:{$lastLetter}{$highRow}")
                      ->getFont()->setBold(true);
                $sheet->getStyle("A{$highRow}:{$lastLetter}{$highRow}")
                      ->getFill()
                      ->setFillType(Fill::FILL_SOLID)
                      ->getStartColor()->setRGB('EEF2FF');
            }
        }

        return [];
    }

    private function colLetter(int $n): string
    {
        $result = '';
        while ($n > 0) {
            $result = chr(65 + (($n - 1) % 26)) . $result;
            $n = intdiv($n - 1, 26);
        }
        return $result;
    }
}
