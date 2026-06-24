<?php

namespace App\Exports;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

class SalesInvoicesExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách hóa đơn bán'; }
    protected function reportTitle(): string { return 'DANH SÁCH HÓA ĐƠN BÁN HÀNG'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($s = $this->filters['search'] ?? null) $parts[] = "Tìm kiếm: {$s}";
        if ($st = $this->filters['status'] ?? null) {
            $label = InvoiceStatus::tryFrom($st)?->label() ?? $st;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả hóa đơn bán';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',                'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Số HĐ',              'width' => 12, 'type' => 'text'],
            ['key' => 'issue_date',  'header' => 'Ngày HĐ',            'width' => 12, 'type' => 'date'],
            ['key' => 'customer',    'header' => 'Khách hàng',         'width' => 30, 'type' => 'text'],
            ['key' => 'subtotal',    'header' => 'Trước VAT (₫)',      'width' => 18, 'type' => 'money'],
            ['key' => 'tax_amount',  'header' => 'VAT (₫)',            'width' => 16, 'type' => 'money'],
            ['key' => 'total',       'header' => 'Tổng thanh toán (₫)','width' => 20, 'type' => 'money'],
            ['key' => 'paid',        'header' => 'Đã thu (₫)',         'width' => 18, 'type' => 'money'],
            ['key' => 'due',         'header' => 'Còn phải thu (₫)',   'width' => 18, 'type' => 'money'],
            ['key' => 'status',      'header' => 'Trạng thái',         'width' => 14, 'type' => 'text'],
            ['key' => 'creator',     'header' => 'Người lập',          'width' => 20, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $search = $this->filters['search'] ?? null;
        $status = $this->filters['status'] ?? null;

        return Invoice::with(['customer', 'creator', 'payments'])
            ->when($search, fn ($q) => $q->where(function ($sq) use ($search) {
                $sq->where('code', 'ilike', "%{$search}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%"));
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($inv) => [
                'code'       => $inv->code,
                'issue_date' => $inv->issue_date?->format('d/m/Y') ?? '',
                'customer'   => $inv->customer->name,
                'subtotal'   => (float) $inv->subtotal,
                'tax_amount' => (float) $inv->tax_amount,
                'total'      => (float) $inv->total,
                'paid'       => $inv->amountPaid(),
                'due'        => $inv->amountDue(),
                'status'     => $inv->status->label(),
                'creator'    => $inv->creator->name,
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        return [
            'code'       => 'Tổng cộng (' . count($rows) . ' hóa đơn)',
            'subtotal'   => array_sum(array_column($rows, 'subtotal')),
            'tax_amount' => array_sum(array_column($rows, 'tax_amount')),
            'total'      => array_sum(array_column($rows, 'total')),
            'paid'       => array_sum(array_column($rows, 'paid')),
            'due'        => array_sum(array_column($rows, 'due')),
        ];
    }
}
