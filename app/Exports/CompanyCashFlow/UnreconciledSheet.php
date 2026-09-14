<?php

namespace App\Exports\CompanyCashFlow;

use App\Enums\CashFlowReconcileStatus;
use App\Exports\CompanyCashFlow\Concerns\SanitizesFormulaInjection;
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

/**
 * Sheet 06 — Chưa đối soát (spec §15/§29). Lọc trực tiếp bằng
 * BankTransaction::reconcileStatus() (nguồn business rule duy nhất) — mọi trạng thái khác
 * Completed đều coi là "chưa đối soát", không viết heuristic riêng.
 *
 * WithStrictNullComparison: xem giải thích ở OverviewSheet — Maatwebsite mặc định coi
 * giá trị 0 như null khi ghi cell (loose comparison).
 */
class UnreconciledSheet implements FromArray, WithHeadings, WithTitle, WithColumnFormatting, WithEvents, WithStrictNullComparison
{
    use SanitizesFormulaInjection;

    private int $lastRow = 1;

    public function __construct(private Collection $transactions, private array $contractLabels)
    {
    }

    public function title(): string
    {
        return '06_Chua_doi_soat';
    }

    public function headings(): array
    {
        return [
            'Ngày', 'Tài khoản', 'Mã giao dịch', 'Tiền vào', 'Tiền ra', 'Nội dung ngân hàng',
            'Đối tượng', 'Category', 'Dự án', 'Hợp đồng', 'Trạng thái đối soát',
            'Vấn đề cần xử lý', 'Người phụ trách',
        ];
    }

    public function columnFormats(): array
    {
        return ['A' => 'dd/mm/yyyy', 'D' => '#,##0', 'E' => '#,##0'];
    }

    private function issueHint(CashFlowReconcileStatus $status): string
    {
        return match ($status) {
            CashFlowReconcileStatus::Unclassified => 'Chưa xác định đối tượng và danh mục',
            CashFlowReconcileStatus::PartyIdentified => 'Đã có đối tượng, chưa có danh mục',
            CashFlowReconcileStatus::Categorized => 'Đã có danh mục, chưa có đối tượng',
            CashFlowReconcileStatus::DocumentLinked => 'Đã liên kết chứng từ, thiếu đối tượng hoặc danh mục',
            CashFlowReconcileStatus::NeedsReview => 'Chuyển khoản nội bộ chưa có giao dịch đối ứng',
            CashFlowReconcileStatus::Completed => '',
        };
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->transactions as $t) {
            /** @var BankTransaction $t */
            $status = $t->reconcileStatus();
            if ($status === CashFlowReconcileStatus::Completed) {
                continue;
            }

            $rows[] = [
                $t->transaction_date ? ExcelDate::PHPToExcel($t->transaction_date) : null,
                $this->safeText($t->bankAccount?->name),
                $this->safeText($t->reference),
                (float) $t->credit > 0 ? (float) $t->credit : null,
                (float) $t->debit > 0 ? (float) $t->debit : null,
                $this->safeText($t->description),
                $this->safeText($t->party_name),
                $this->safeText($t->cashFlowCategory?->name),
                $this->safeText($t->project?->name),
                $this->safeText($this->contractLabels[$t->id] ?? null),
                $status->label(),
                $this->issueHint($status),
                $this->safeText($t->responsibleUser?->name),
            ];
        }

        $this->lastRow = count($rows) + 1;

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:M1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B91C1C']],
                ]);
                $sheet->freezePane('A2');
                if ($this->lastRow >= 1) {
                    $sheet->setAutoFilter("A1:M{$this->lastRow}");
                }
            },
        ];
    }
}
