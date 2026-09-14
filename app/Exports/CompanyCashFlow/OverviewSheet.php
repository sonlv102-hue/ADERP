<?php

namespace App\Exports\CompanyCashFlow;

use App\Exports\CompanyCashFlow\Concerns\SanitizesFormulaInjection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet 01 — Tổng quan (spec §8). Toàn bộ số liệu lấy trực tiếp từ
 * CompanyCashFlowReportService::summary(), không tính lại.
 *
 * WithStrictNullComparison: mặc định Maatwebsite Excel so sánh giá trị với null bằng `==`
 * (loose) khi quyết định có ghi cell hay không -> KPI = 0.0 thật (vd không có giao dịch nào
 * trong kỳ) bị coi như "null" và bị bỏ trống thay vì hiện số 0 — phát hiện qua test riêng,
 * không phải bug nghiệp vụ. Bắt buộc interface này để 0 vẫn hiện đúng là "0".
 */
class OverviewSheet implements FromArray, WithTitle, WithEvents, WithColumnWidths, WithStrictNullComparison
{
    use SanitizesFormulaInjection;

    private int $kpiStartRow = 0;
    private int $kpiEndRow = 0;

    public function __construct(private array $summary, private array $meta)
    {
    }

    public function title(): string
    {
        return '01_Tong_quan';
    }

    public function columnWidths(): array
    {
        return ['A' => 42, 'B' => 30];
    }

    private function fmtDate(?string $date): string
    {
        return $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : '';
    }

    public function array(): array
    {
        $rows = [];
        $rows[] = ['BÁO CÁO DÒNG TIỀN TÀI KHOẢN CÔNG TY'];
        $rows[] = [null];
        $rows[] = ['Từ ngày', $this->fmtDate($this->meta['from'] ?? null)];
        $rows[] = ['Đến ngày', $this->fmtDate($this->meta['to'] ?? null)];
        // Tên tài khoản/dự án/danh mục/người xuất là text do người dùng đặt (form chỉ
        // validate required|string) -> sanitize như mọi free-text khác (spec §22).
        $rows[] = ['Tài khoản ngân hàng', $this->safeText($this->meta['bank_account'] ?? 'Tất cả')];
        $rows[] = ['Dự án', $this->safeText($this->meta['project'] ?? 'Tất cả')];
        $rows[] = ['Danh mục', $this->safeText($this->meta['category'] ?? 'Tất cả')];
        $rows[] = ['Chiều tiền', $this->meta['direction'] ?? 'Tất cả'];
        $rows[] = ['Trạng thái đối soát', $this->meta['reconcile_status'] ?? 'Tất cả'];
        $rows[] = ['Đơn vị tiền tệ', 'VNĐ'];
        $rows[] = ['Ngày giờ xuất', optional($this->meta['exported_at'] ?? null)->format('d/m/Y H:i')];
        $rows[] = ['Người xuất', $this->safeText($this->meta['exported_by'] ?? '')];
        $rows[] = [null];

        $rows[] = ['CHỈ TIÊU', 'GIÁ TRỊ (VNĐ)'];
        $this->kpiStartRow = count($rows) + 1;
        $rows[] = ['Số dư đầu kỳ', (float) $this->summary['opening_balance']];
        $rows[] = ['Tổng tiền vào', (float) $this->summary['inflow']];
        $rows[] = ['Tổng tiền ra', (float) $this->summary['outflow']];
        $rows[] = ['Dòng tiền thuần', (float) $this->summary['net_cash_flow']];
        $rows[] = ['Số dư cuối kỳ', (float) $this->summary['closing_balance']];
        $rows[] = ['Tiền vào chưa xác định nguồn', (float) $this->summary['unclassified_inflow']];
        $rows[] = ['Tiền ra chưa xác định mục đích', (float) $this->summary['unclassified_outflow']];
        $this->kpiEndRow = count($rows);

        $rows[] = [null];
        $rows[] = [$this->balanceSourceNote()];

        return $rows;
    }

    // spec §9: đọc balance_source thật, không hard-code "calculated" là source duy nhất mãi mãi.
    private function balanceSourceNote(): string
    {
        return match ($this->summary['balance_source'] ?? 'calculated') {
            'calculated' => 'Nguồn số dư: Tính toán từ dữ liệu giao dịch hiện có, chưa phải số dư xác nhận trực tiếp từ ngân hàng.',
            default => 'Nguồn số dư: ' . $this->summary['balance_source'],
        };
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
                $sheet->mergeCells('A1:B1');

                $headerRow = $this->kpiStartRow - 1;
                $sheet->getStyle("A{$headerRow}:B{$headerRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'D0E4F7']],
                ]);

                $sheet->getStyle("B{$this->kpiStartRow}:B{$this->kpiEndRow}")
                    ->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("A{$this->kpiStartRow}:B{$this->kpiEndRow}")
                    ->getFont()->setBold(true);

                $noteRow = $sheet->getHighestRow();
                $sheet->mergeCells("A{$noteRow}:B{$noteRow}");
                $sheet->getStyle("A{$noteRow}")->getFont()->setItalic(true)->setSize(9);
                $sheet->getStyle("A{$noteRow}")->getAlignment()->setWrapText(true);

                $sheet->freezePaneByColumnAndRow(1, 2);
            },
        ];
    }
}
