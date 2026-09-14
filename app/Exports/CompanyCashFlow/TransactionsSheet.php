<?php

namespace App\Exports\CompanyCashFlow;

use App\Exports\CompanyCashFlow\Concerns\SanitizesFormulaInjection;
use App\Helpers\PartyTypeLabels;
use App\Models\BankTransaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sheet 02 — Giao dịch (spec §10/§11/§20). Toàn bộ transaction thỏa filter, KHÔNG
 * phân trang — $transactions truyền vào là kết quả CompanyCashFlowReportService::
 * transactionsForExport(), không phải bộ query riêng.
 *
 * WithStrictNullComparison: xem giải thích ở OverviewSheet — Maatwebsite mặc định coi
 * giá trị 0 như null khi ghi cell (loose comparison).
 */
class TransactionsSheet implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithEvents, WithStrictNullComparison
{
    use SanitizesFormulaInjection;

    public function __construct(private Collection $transactions, private array $contractLabels)
    {
    }

    public function title(): string
    {
        return '02_Giao_dich';
    }

    public function headings(): array
    {
        return [
            'STT', 'Ngày giao dịch', 'Ngày hạch toán', 'Ngân hàng', 'Tài khoản công ty',
            'Mã giao dịch ngân hàng', 'Loại giao dịch', 'Tiền vào', 'Tiền ra',
            'Đối tượng', 'Loại đối tượng', 'Tài khoản đối ứng', 'Ngân hàng đối ứng',
            'Nguồn tiền / Mục đích', 'Dự án', 'Loại hợp đồng', 'Hợp đồng', 'Người phụ trách',
            'Nội dung ngân hàng', 'Ghi chú', 'Trạng thái đối soát', 'Giao dịch đối ứng nội bộ',
            'Số dư sau giao dịch', 'Ngày phân loại/cập nhật',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'B' => 'dd/mm/yyyy',
            'C' => 'dd/mm/yyyy',
            'H' => '#,##0',
            'I' => '#,##0',
            'W' => '#,##0',
            'X' => 'dd/mm/yyyy hh:mm',
        ];
    }

    private function excelDate(?\Illuminate\Support\Carbon $date): ?float
    {
        return $date ? ExcelDate::PHPToExcel($date) : null;
    }

    private function contractTypeLabel(?string $type): string
    {
        return match ($type) {
            'contract' => 'Hợp đồng bán',
            'purchase_contract' => 'Hợp đồng mua',
            default => '',
        };
    }

    public function array(): array
    {
        $rows = [];
        $seq = 1;

        foreach ($this->transactions as $t) {
            /** @var BankTransaction $t */
            $isInflow = (float) $t->credit > 0;

            $pairedWith = $t->pairedTransaction
                ? sprintf(
                    '%s — %s — %s',
                    $t->pairedTransaction->bankAccount?->name ?? '',
                    optional($t->pairedTransaction->transaction_date)->format('d/m/Y') ?? '',
                    number_format((float) ($t->pairedTransaction->debit ?: $t->pairedTransaction->credit), 0, ',', '.')
                )
                : '';

            $rows[] = [
                $seq++,
                $this->excelDate($t->transaction_date),
                $this->excelDate($t->value_date),
                $this->safeText($t->bankAccount?->bank_name),
                $this->safeText($t->bankAccount?->name),
                $this->safeText($t->reference),
                $isInflow ? 'Tiền vào' : 'Tiền ra',
                $isInflow ? (float) $t->credit : null,
                !$isInflow ? (float) $t->debit : null,
                $this->safeText($t->party_name),
                PartyTypeLabels::label($t->party_type),
                $this->safeText($t->counterpart_account),
                $this->safeText($t->counterpart_bank),
                $this->safeText($t->cashFlowCategory?->name ?? 'Chưa xác định'),
                $this->safeText($t->project?->name),
                $this->contractTypeLabel($t->contract_type),
                $this->safeText($this->contractLabels[$t->id] ?? null),
                $this->safeText($t->responsibleUser?->name),
                $this->safeText($t->description),
                $this->safeText($t->cash_flow_note),
                $t->reconcileStatus()->label(),
                $this->safeText($pairedWith),
                null, // Số dư sau giao dịch — Phase 1 backend chưa có nguồn dữ liệu này (không bịa số)
                $this->excelDate($t->updated_at),
            ];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastCol = 'X';

                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                ]);

                $sheet->freezePane('A2');
                if ($lastRow >= 1) {
                    $sheet->setAutoFilter("A1:{$lastCol}{$lastRow}");
                }
            },
        ];
    }
}
