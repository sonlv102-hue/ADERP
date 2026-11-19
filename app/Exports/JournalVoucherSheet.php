<?php

namespace App\Exports;

use App\Exports\Concerns\HasSignatureBlock;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Một sheet = một Phiếu kế toán; bố cục khớp pdf/journal-voucher.blade.php. */
class JournalVoucherSheet implements FromArray, WithEvents, WithTitle
{
    use HasSignatureBlock;

    private const WIDTHS  = ['A' => 6, 'B' => 11, 'C' => 26, 'D' => 36, 'E' => 20, 'F' => 16, 'G' => 16];
    private const HEADERS = ['STT', 'Số hiệu TK', 'Tên tài khoản', 'Diễn giải', 'Đối tượng', 'Nợ', 'Có'];

    public function __construct(
        private array $v,
        private array $company,
        private ?string $signingPlace,
        private array $signers,
        private string $sheetTitle,
    ) {}

    public function title(): string { return $this->sheetTitle; }

    public function array(): array { return []; }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->render($e->sheet->getDelegate())];
    }

    private function render(Worksheet $s): void
    {
        $v = $this->v;
        foreach (self::WIDTHS as $col => $w) {
            $s->getColumnDimension($col)->setWidth($w);
        }

        // ── Đầu phiếu ──
        $this->text($s, 'A1', $this->company['name'], 'A1:E1')->getFont()->setBold(true)->setSize(11);
        $this->text($s, 'A2', $this->company['address'] ? 'Địa chỉ: ' . $this->company['address'] : '', 'A2:E2')->getFont()->setSize(9);
        $this->text($s, 'A3', $this->company['tax_code'] ? 'MST: ' . $this->company['tax_code'] : '', 'A3:E3')->getFont()->setSize(9);
        $this->text($s, 'F1', 'Số: ' . $v['code'], 'F1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $s->getStyle('F1')->getFont()->setBold(true);

        $this->text($s, 'A5', 'PHIẾU KẾ TOÁN', 'A5:G5')->applyFromArray([
            'font' => ['bold' => true, 'size' => 15], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $d = $v['entry_date'];
        $this->text($s, 'A6', "Ngày {$d->format('d')} tháng {$d->format('m')} năm {$d->format('Y')}", 'A6:G6')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        if ($v['status'] !== 'posted') {
            $label = 'Trạng thái: ' . $v['status_label'] . ($v['status'] === 'draft' ? ' — chưa hạch toán' : '');
            $this->text($s, 'A7', $label, 'A7:G7')->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'B91C1C']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $this->text($s, 'A8', 'Diễn giải: ' . $v['description'], 'A8:G8')->getAlignment()->setWrapText(true);

        // ── Bảng dòng bút toán ──
        $hdr = 10;
        foreach (self::HEADERS as $i => $h) {
            $s->setCellValue(chr(65 + $i) . $hdr, $h);
        }
        $s->getStyle("A{$hdr}:G{$hdr}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $r = $hdr;
        foreach ($v['lines'] as $i => $l) {
            $r++;
            $s->setCellValue("A{$r}", $i + 1);
            $s->setCellValueExplicit("B{$r}", (string) $l['account_code'], DataType::TYPE_STRING);
            $s->setCellValueExplicit("C{$r}", $l['account_name'], DataType::TYPE_STRING);
            $s->setCellValueExplicit("D{$r}", $l['description'], DataType::TYPE_STRING);
            $s->setCellValueExplicit("E{$r}", $l['object'], DataType::TYPE_STRING);
            if ($l['debit'] > 0)  $s->setCellValue("F{$r}", $l['debit']);
            if ($l['credit'] > 0) $s->setCellValue("G{$r}", $l['credit']);
        }

        $total = $r + 1;
        $s->mergeCells("A{$total}:E{$total}");
        $s->setCellValue("A{$total}", 'TỔNG CỘNG');
        $s->setCellValue("F{$total}", $v['total_debit']);
        $s->setCellValue("G{$total}", $v['total_credit']);
        $s->getStyle("A{$total}:G{$total}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EFF6FF']],
        ]);

        $s->getStyle("A{$hdr}:G{$total}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9CA3AF');
        $s->getStyle('A' . ($hdr + 1) . ":G{$total}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $s->getStyle("A{$hdr}:B{$total}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle("F{$hdr}:G{$total}")->getNumberFormat()->setFormatCode('#,##0');
        $s->getStyle('F' . ($hdr + 1) . ":G{$total}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // ── Bằng chữ, ghi chú ──
        $row = $total + 2;
        $this->text($s, "A{$row}", 'Số tiền bằng chữ: ' . $v['amount_words'], "A{$row}:G{$row}")->getFont()->setItalic(true);
        if ($v['notes'] !== '') {
            $row++;
            $this->text($s, "A{$row}", 'Ghi chú: ' . $v['notes'], "A{$row}:G{$row}")->getAlignment()->setWrapText(true);
        }

        // ── Chữ ký (shared component — see docs/REPORTING_STANDARDS.md) ──
        $this->writeSignatureBlock(
            $s, $row + 2, $this->signers, $this->signingPlace,
            "ngày {$d->format('d')} tháng {$d->format('m')} năm {$d->format('Y')}", 'A', 'G',
        );

        $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    }

    /** Ghi chuỗi (luôn kiểu text — chặn Excel formula injection) và merge vùng. */
    private function text(Worksheet $s, string $cell, string $value, string $merge)
    {
        $s->mergeCells($merge);
        $s->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);

        return $s->getStyle($cell);
    }
}
