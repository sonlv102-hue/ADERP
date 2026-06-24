<?php

namespace App\Exports;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;

class PurchaseInvoicesExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách hóa đơn đầu vào'; }
    protected function reportTitle(): string { return 'DANH SÁCH HÓA ĐƠN ĐẦU VÀO'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($s = $this->filters['search'] ?? null) $parts[] = "Tìm kiếm: {$s}";
        if ($st = $this->filters['status'] ?? null) {
            $label = PurchaseInvoiceStatus::tryFrom($st)?->label() ?? $st;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả hóa đơn đầu vào';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',          'header' => 'STT',              'width' => 5,  'type' => 'number'],
            ['key' => 'code',           'header' => 'Mã nội bộ',        'width' => 12, 'type' => 'text'],
            ['key' => 'invoice_number', 'header' => 'Số HĐ NCC',        'width' => 16, 'type' => 'text'],
            ['key' => 'invoice_date',   'header' => 'Ngày HĐ',          'width' => 12, 'type' => 'date'],
            ['key' => 'supplier',       'header' => 'Nhà cung cấp',     'width' => 30, 'type' => 'text'],
            ['key' => 'invoice_type',   'header' => 'Loại HĐ',          'width' => 20, 'type' => 'text'],
            ['key' => 'project',        'header' => 'Dự án',            'width' => 12, 'type' => 'text'],
            ['key' => 'subtotal',       'header' => 'Trước VAT (₫)',    'width' => 18, 'type' => 'money'],
            ['key' => 'tax_amount',     'header' => 'VAT đầu vào (₫)', 'width' => 16, 'type' => 'money'],
            ['key' => 'total',          'header' => 'Tổng thanh toán (₫)','width' => 20,'type' => 'money'],
            ['key' => 'paid',           'header' => 'Đã thanh toán (₫)','width' => 18, 'type' => 'money'],
            ['key' => 'due',            'header' => 'Còn phải trả (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'status',         'header' => 'Trạng thái',       'width' => 16, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $search = $this->filters['search'] ?? null;
        $status = $this->filters['status'] ?? null;

        return PurchaseInvoice::with(['supplier', 'project', 'payments'])
            ->when($search, fn ($q) => $q->where(function ($sq) use ($search) {
                $sq->where('code', 'ilike', "%{$search}%")
                   ->orWhere('invoice_number', 'ilike', "%{$search}%")
                   ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$search}%"));
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($inv) => [
                'code'           => $inv->code,
                'invoice_number' => $inv->invoice_number ?? '',
                'invoice_date'   => $inv->invoice_date?->format('d/m/Y') ?? '',
                'supplier'       => $inv->supplier->name,
                'invoice_type'   => $inv->invoice_type?->label() ?? '',
                'project'        => $inv->project?->code ?? '',
                'subtotal'       => (float) $inv->subtotal,
                'tax_amount'     => (float) $inv->tax_amount,
                'total'          => (float) $inv->total,
                'paid'           => $inv->amountPaid(),
                'due'            => $inv->amountDue(),
                'status'         => $inv->status->label(),
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
