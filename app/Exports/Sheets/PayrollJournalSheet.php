<?php

namespace App\Exports\Sheets;

use App\Models\Payroll;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PayrollJournalSheet implements FromArray, WithTitle, WithEvents, WithColumnFormatting, ShouldAutoSize
{
    public function __construct(
        private Payroll $payroll,
        private Collection $items,
    ) {}

    public function title(): string
    {
        return 'Hạch toán lương';
    }

    public function columnFormats(): array
    {
        return ['D' => '#,##0', 'E' => '#,##0'];
    }

    public function array(): array
    {
        [$year, $month] = explode('-', $this->payroll->period);
        $rows   = [];
        $rows[] = ["BẢNG HẠCH TOÁN LƯƠNG - THÁNG {$month}/{$year}"];
        $rows[] = ["Mã bảng lương: {$this->payroll->code}"];
        $rows[] = [];
        $rows[] = ['STT', 'Số bút toán', 'Diễn giải', 'Nợ TK', 'Có TK', 'Số tiền (VND)', 'Ghi chú'];

        // Gather JE codes from items
        $jeCodes = $this->items
            ->filter(fn($i) => !empty($i['salary_journal_entry']))
            ->map(fn($i) => $i['salary_journal_entry']['code'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($jeCodes->isEmpty()) {
            $rows[] = ['', '', 'Bảng lương chưa được xác nhận — bút toán chưa được tạo.', '', '', '', ''];
            return $rows;
        }

        $jeIds = $this->items
            ->filter(fn($i) => !empty($i['salary_journal_entry']))
            ->map(fn($i) => $i['salary_journal_entry']['id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $lines = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.journal_entry_id', $jeIds)
            ->orderBy('jel.journal_entry_id')
            ->orderBy('jel.sort_order')
            ->select('je.code as je_code', 'je.description', 'jel.account_code', 'jel.debit', 'jel.credit', 'jel.description as line_desc')
            ->get();

        $seq = 1;
        foreach ($lines->groupBy('je_code') as $jeCode => $jeLines) {
            foreach ($jeLines as $line) {
                $rows[] = [
                    $seq++,
                    $jeCode,
                    $line->description ?: $line->line_desc ?: '',
                    $line->debit > 0 ? $line->account_code : '',
                    $line->credit > 0 ? $line->account_code : '',
                    max((float)$line->debit, (float)$line->credit),
                    $line->line_desc ?? '',
                ];
            }
        }

        // Summary totals from payroll
        $rows[] = [];
        $rows[] = ['', '', 'TỔNG CHI PHÍ LƯƠNG', '', '', (float)$this->payroll->total_gross, ''];
        $rows[] = ['', '', 'TỔNG THỰC CHI (lương thực lĩnh)', '', '', (float)$this->payroll->total_net_salary + (float)($this->payroll->total_adjustment ?? 0), ''];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:G1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->getStyle('A4:G4')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7C3AED']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
                ]);
            },
        ];
    }
}
