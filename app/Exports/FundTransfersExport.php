<?php

namespace App\Exports;

use App\Enums\FundTransferStatus;
use App\Models\FundTransfer;

class FundTransfersExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        return 'Danh sách luân chuyển quỹ';
    }

    protected function reportTitle(): string
    {
        return 'DANH SÁCH LUÂN CHUYỂN QUỸ';
    }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($s = $this->filters['status'] ?? null) {
            $label = FundTransferStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả phiếu';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',         'header' => 'STT',          'width' => 5,  'type' => 'number'],
            ['key' => 'transfer_no',   'header' => 'Số phiếu',     'width' => 14, 'type' => 'text'],
            ['key' => 'transfer_date', 'header' => 'Ngày',         'width' => 12, 'type' => 'date'],
            ['key' => 'from_fund',     'header' => 'Từ quỹ',       'width' => 22, 'type' => 'text'],
            ['key' => 'to_fund',       'header' => 'Đến quỹ',      'width' => 22, 'type' => 'text'],
            ['key' => 'amount',        'header' => 'Số tiền (₫)',  'width' => 18, 'type' => 'money'],
            ['key' => 'description',   'header' => 'Diễn giải',    'width' => 36, 'type' => 'text'],
            ['key' => 'status',        'header' => 'Trạng thái',   'width' => 14, 'type' => 'text'],
            ['key' => 'creator',       'header' => 'Người tạo',    'width' => 18, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $status = $this->filters['status'] ?? null;

        return FundTransfer::with('fromFund', 'toFund', 'creator')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => [
                'transfer_no'   => $t->transfer_no,
                'transfer_date' => $t->transfer_date->format('d/m/Y'),
                'from_fund'     => $t->fromFund?->name ?? '',
                'to_fund'       => $t->toFund?->name ?? '',
                'amount'        => (float) $t->amount,
                'description'   => $t->description ?? '',
                'status'        => $t->status->label(),
                'creator'       => $t->creator?->name ?? '',
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'amount'));
        return [
            'transfer_no' => 'Tổng cộng (' . count($rows) . ' phiếu)',
            'amount'      => $sum,
        ];
    }
}
