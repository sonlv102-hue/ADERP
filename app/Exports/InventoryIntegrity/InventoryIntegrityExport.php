<?php

namespace App\Exports\InventoryIntegrity;

use App\Models\InventoryAuditRun;
use App\Services\Inventory\Integrity\IntegrityReportQuery;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel "Kiểm soát sai lệch tồn kho và kế toán": cùng truy vấn/quyền với giao diện (IntegrityReportQuery::filtered),
 * cùng lần audit gần nhất. Mỗi vấn đề xuất hiện ở sheet nhóm sai lệch của nó và sheet nhóm phụ trách của nó.
 */
class InventoryIntegrityExport implements WithMultipleSheets
{
    public function __construct(private Collection $issues, private array $kpis, private ?InventoryAuditRun $run, private array $filters) {}

    public function sheets(): array
    {
        $g = IntegrityReportQuery::KPI_GROUPS;
        $by = fn (array $rules) => $this->issues->whereIn('rule_code', $rules)->values();
        $bucket = fn (string $b) => $this->issues->filter(fn ($i) => IntegrityReportQuery::bucket($i) === $b)->values();
        return [
            new IntegritySummarySheet($this->kpis, $this->run, $this->filters, $this->issues->count()),
            new IntegrityIssueSheet('Sai lệch số lượng', $by(array_merge($g['quantity'], $g['negative'], $g['phantom']))),
            new IntegrityIssueSheet('Giá trị và giá vốn', $by(array_merge($g['value'], $g['cost']))),
            new IntegrityIssueSheet('Sai lệch chứng từ', $by($g['document'])),
            new IntegrityIssueSheet('Sai lệch kế toán', $by(array_merge($g['accounting'], $g['missing_je']))),
            new IntegrityIssueSheet('Chờ DEV', $bucket('dev')),
            new IntegrityIssueSheet('Chờ kế toán', $bucket('accounting')),
            new IntegrityIssueSheet('Chờ quyết định nghiệp vụ', $bucket('policy')->merge($bucket('verification'))->values()),
            new IntegrityIssueSheet('Đã xử lý', $bucket('resolved')),
            new IntegrityIssueSheet('Chênh lệch hợp lệ', $bucket('accepted')),
        ];
    }
}
