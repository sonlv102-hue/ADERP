<?php

namespace App\Exports\CompanyCashFlow;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Workbook "Dòng tiền tài khoản công ty" (Phase 1.1, spec §7). Toàn bộ dữ liệu truyền
 * vào đã được CompanyCashFlowController::export() lấy từ CompanyCashFlowReportService —
 * class này chỉ định dạng, không tính toán business logic.
 */
class CompanyCashFlowExport implements WithMultipleSheets
{
    public function __construct(
        private array $summary,
        private Collection $transactions,
        private array $contractLabels,
        private Collection $byCategory,
        private array $parties,
        private array $meta,
    ) {
    }

    public function sheets(): array
    {
        return [
            new OverviewSheet($this->summary, $this->meta),
            new TransactionsSheet($this->transactions, $this->contractLabels),
            new InflowSheet($this->byCategory),
            new OutflowSheet($this->byCategory),
            new PartySheet($this->parties),
            new UnreconciledSheet($this->transactions, $this->contractLabels),
        ];
    }
}
