<?php

namespace App\Exports;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\CashVoucher;

class CashVouchersExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        $type = $this->filters['type'] ?? null;
        if ($type === 'receipt') return 'Danh sách phiếu thu';
        if ($type === 'payment') return 'Danh sách phiếu chi';
        return 'Danh sách phiếu thu chi';
    }

    protected function reportTitle(): string
    {
        $type = $this->filters['type'] ?? null;
        if ($type === 'receipt') return 'DANH SÁCH PHIẾU THU';
        if ($type === 'payment') return 'DANH SÁCH PHIẾU CHI';
        return 'DANH SÁCH PHIẾU THU CHI';
    }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($t = $this->filters['type'] ?? null) {
            $label = CashVoucherType::tryFrom($t)?->label() ?? $t;
            $parts[] = "Loại: {$label}";
        }
        if ($s = $this->filters['status'] ?? null) {
            $label = CashVoucherStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        if ($q = $this->filters['search'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        return $parts ? implode('   |   ', $parts) : 'Tất cả phiếu thu chi';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',               'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Số phiếu',          'width' => 12, 'type' => 'text'],
            ['key' => 'voucher_date','header' => 'Ngày phiếu',        'width' => 12, 'type' => 'date'],
            ['key' => 'type',        'header' => 'Loại',              'width' => 12, 'type' => 'text'],
            ['key' => 'counterparty','header' => 'Đối tượng',         'width' => 30, 'type' => 'text'],
            ['key' => 'description', 'header' => 'Diễn giải',         'width' => 40, 'type' => 'text'],
            ['key' => 'fund',        'header' => 'Quỹ',               'width' => 20, 'type' => 'text'],
            ['key' => 'amount',      'header' => 'Số tiền (₫)',       'width' => 18, 'type' => 'money'],
            ['key' => 'status',      'header' => 'Trạng thái',        'width' => 14, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $type   = $this->filters['type']   ?? null;
        $status = $this->filters['status'] ?? null;
        $fundId = $this->filters['fund_id'] ?? null;
        $search = $this->filters['search'] ?? null;

        return CashVoucher::with(['fund', 'creator'])
            ->when($type,   fn ($q) => $q->where('type', $type))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($fundId, fn ($q) => $q->where('fund_id', $fundId))
            ->when($search, fn ($q) => $q->where(function ($sq) use ($search) {
                $sq->where('code', 'ilike', "%{$search}%")
                   ->orWhere('description', 'ilike', "%{$search}%")
                   ->orWhere('counterparty', 'ilike', "%{$search}%");
            }))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($v) => [
                'code'         => $v->code,
                'voucher_date' => $v->voucher_date?->format('d/m/Y') ?? '',
                'type'         => $v->type->label(),
                'counterparty' => $v->counterparty ?? '',
                'description'  => $v->description ?? '',
                'fund'         => $v->fund?->name ?? '',
                'amount'       => (float) $v->amount,
                'status'       => $v->status->label(),
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'amount'));
        return [
            'code'   => 'Tổng cộng (' . count($rows) . ' phiếu)',
            'amount' => $sum,
        ];
    }
}
