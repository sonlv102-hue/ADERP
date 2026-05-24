<?php

namespace App\Exports\Reports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CashFlowExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Thu Chi'; }

    public function collection()
    {
        $dateFrom = $this->filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo   = $this->filters['date_to']   ?? now()->toDateString();
        $method   = $this->filters['method']    ?? null;
        $type     = $this->filters['type']      ?? null;

        $inflow = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw("payments.payment_date as date, 'in' as type, invoices.code as ref_code, CONCAT('Thu HĐ ', invoices.code, ' - ', customers.name) as description, payments.method, payments.amount")
            ->whereBetween('payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('payments.method', $method))
            ->get()->map(fn ($r) => (array) $r);

        $outflow = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->selectRaw("purchase_invoice_payments.payment_date as date, 'out' as type, purchase_invoices.code as ref_code, CONCAT('Trả HĐ ', purchase_invoices.code, ' - ', suppliers.name) as description, purchase_invoice_payments.method, purchase_invoice_payments.amount")
            ->whereBetween('purchase_invoice_payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('purchase_invoice_payments.method', $method))
            ->get()->map(fn ($r) => (array) $r);

        $all = collect();
        if ($type !== 'out') $all = $all->merge($inflow);
        if ($type !== 'in')  $all = $all->merge($outflow);

        $balance = 0;
        return $all->sortBy('date')->values()->map(function ($row) use (&$balance) {
            $delta = $row['type'] === 'in' ? (float) $row['amount'] : -(float) $row['amount'];
            $balance += $delta;
            return array_merge($row, ['balance' => $balance]);
        });
    }

    public function headings(): array
    {
        return ['Ngày', 'Loại', 'Nội dung', 'Phương thức', 'Thu', 'Chi', 'Số dư lũy kế'];
    }

    public function map($row): array
    {
        $row = (array) $row;
        return [
            $row['date'],
            $row['type'] === 'in' ? 'Thu' : 'Chi',
            $row['description'],
            $row['method'],
            $row['type'] === 'in'  ? $row['amount'] : 0,
            $row['type'] === 'out' ? $row['amount'] : 0,
            $row['balance'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
