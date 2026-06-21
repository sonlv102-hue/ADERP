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

    public function title(): string { return 'Sổ thu chi theo ngày'; }

    public function collection()
    {
        $dateFrom = $this->filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo   = $this->filters['date_to']   ?? now()->toDateString();
        $method   = $this->filters['method']    ?? null;
        $type     = $this->filters['type']      ?? null;

        $inflow = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw("payments.payment_date as date, 'in' as type, invoices.code as ref_code, 'Thu HĐ ' || invoices.code || ' - ' || customers.name as description, payments.method, '' as fund_name, payments.amount")
            ->whereBetween('payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('payments.method', $method))
            ->get()->map(fn ($r) => (array) $r);

        $outflow = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->selectRaw("purchase_invoice_payments.payment_date as date, 'out' as type, purchase_invoices.code as ref_code, 'Trả HĐ ' || purchase_invoices.code || ' - ' || suppliers.name as description, purchase_invoice_payments.method, '' as fund_name, purchase_invoice_payments.amount")
            ->where('purchase_invoice_payments.status', 'active')
            ->whereBetween('purchase_invoice_payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('purchase_invoice_payments.method', $method))
            ->get()->map(fn ($r) => (array) $r);

        $voucherIn = DB::table('cash_vouchers')
            ->leftJoin('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->selectRaw("cash_vouchers.voucher_date as date, 'in' as type, cash_vouchers.code as ref_code, '[PT] ' || COALESCE(cash_vouchers.description, cash_vouchers.code) as description, CASE WHEN funds.type = 'bank' THEN 'bank_transfer' ELSE 'cash' END as method, COALESCE(funds.name, '') as fund_name, cash_vouchers.amount")
            ->where('cash_vouchers.type', 'receipt')
            ->where('cash_vouchers.status', 'confirmed')
            ->whereNotIn('cash_vouchers.business_type', ['collect_customer', 'pay_supplier'])
            ->whereBetween('cash_vouchers.voucher_date', [$dateFrom, $dateTo])
            ->when($method, function ($q) use ($method) {
                if ($method === 'bank_transfer') {
                    $q->where('funds.type', 'bank');
                } elseif ($method === 'cash') {
                    $q->where(fn ($q2) => $q2->where('funds.type', 'cash')->orWhereNull('funds.id'));
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->get()->map(fn ($r) => (array) $r);

        $voucherOut = DB::table('cash_vouchers')
            ->leftJoin('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->selectRaw("cash_vouchers.voucher_date as date, 'out' as type, cash_vouchers.code as ref_code, '[PC] ' || COALESCE(cash_vouchers.description, cash_vouchers.code) as description, CASE WHEN funds.type = 'bank' THEN 'bank_transfer' ELSE 'cash' END as method, COALESCE(funds.name, '') as fund_name, cash_vouchers.amount")
            ->where('cash_vouchers.type', 'payment')
            ->where('cash_vouchers.status', 'confirmed')
            ->whereNotIn('cash_vouchers.business_type', ['collect_customer', 'pay_supplier'])
            ->whereBetween('cash_vouchers.voucher_date', [$dateFrom, $dateTo])
            ->when($method, function ($q) use ($method) {
                if ($method === 'bank_transfer') {
                    $q->where('funds.type', 'bank');
                } elseif ($method === 'cash') {
                    $q->where(fn ($q2) => $q2->where('funds.type', 'cash')->orWhereNull('funds.id'));
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->get()->map(fn ($r) => (array) $r);

        $all = collect();
        if ($type !== 'out') {
            $all = $all->merge($inflow)->merge($voucherIn);
        }
        if ($type !== 'in') {
            $all = $all->merge($outflow)->merge($voucherOut);
        }

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
        $methodLabels = ['cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản', 'other' => 'Khác'];
        $methodLabel  = $methodLabels[$row['method']] ?? $row['method'];
        if (!empty($row['fund_name'])) {
            $methodLabel .= ' — ' . $row['fund_name'];
        }
        return [
            $row['date'],
            $row['type'] === 'in' ? 'Thu' : 'Chi',
            $row['description'],
            $methodLabel,
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
