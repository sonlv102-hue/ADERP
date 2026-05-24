<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AccountLedgerExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        $account = $this->filters['account'] ?? '131';
        $names   = ['131' => 'TK131 Phải thu KH', '331' => 'TK331 Phải trả NCC', '156' => 'TK156 Hàng hóa'];
        return $names[$account] ?? 'Sổ chi tiết TK';
    }

    public function collection(): Collection
    {
        $account = $this->filters['account'] ?? '131';
        $year    = (int) ($this->filters['year'] ?? now()->year);
        $from    = $this->filters['date_from'] ?? "{$year}-01-01";
        $to      = $this->filters['date_to']   ?? "{$year}-12-31";
        $prev    = date('Y-m-d', strtotime($from . ' -1 day'));

        [$opening, $rows] = $this->build($account, $from, $to, $prev);

        $balance = $opening;
        $result  = [];

        $result[] = (object)['date' => '', 'ref' => '', 'description' => 'Số dư đầu kỳ', 'partner' => '', 'debit' => 0, 'credit' => 0, 'balance' => $opening];

        foreach ($rows as $row) {
            if ($account === '331') {
                $balance = $balance + $row['credit'] - $row['debit'];
            } else {
                $balance = $balance + $row['debit'] - $row['credit'];
            }
            $result[] = (object)['date' => $row['date'], 'ref' => $row['ref'], 'description' => $row['description'],
                'partner' => $row['partner'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'balance' => $balance];
        }

        return collect($result);
    }

    private function build(string $account, string $from, string $to, string $prev): array
    {
        if ($account === '131') {
            $iBefore = (float) DB::table('invoices')->whereNotIn('status', ['cancelled'])->where('issue_date', '<=', $prev)->sum('total');
            $pBefore = (float) DB::table('payments')->where('payment_date', '<=', $prev)->sum('amount');
            $opening = $iBefore - $pBefore;
            $rows    = [];
            foreach (DB::table('invoices')->join('customers', 'customers.id', '=', 'invoices.customer_id')->whereNotIn('invoices.status', ['cancelled'])->whereBetween('invoices.issue_date', [$from, $to])->orderBy('invoices.issue_date')->get() as $r) {
                $rows[] = ['date' => $r->issue_date, 'ref' => $r->code, 'description' => 'Xuất HĐ: ' . $r->code, 'partner' => $r->name, 'debit' => (float)$r->total, 'credit' => 0.0];
            }
            foreach (DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->join('customers', 'customers.id', '=', 'invoices.customer_id')->whereBetween('payments.payment_date', [$from, $to])->orderBy('payments.payment_date')->get() as $r) {
                $rows[] = ['date' => $r->payment_date, 'ref' => $r->code, 'description' => 'Thu tiền / HĐ: ' . $r->code, 'partner' => $r->name, 'debit' => 0.0, 'credit' => (float)$r->amount];
            }
        } elseif ($account === '331') {
            $piBefore  = (float) DB::table('purchase_invoices')->whereNotIn('status', ['cancelled'])->whereNotNull('invoice_date')->where('invoice_date', '<=', $prev)->sum('total');
            $appBefore = (float) DB::table('purchase_invoice_payments')->where('payment_date', '<=', $prev)->sum('amount');
            $opening   = $piBefore - $appBefore;
            $rows      = [];
            foreach (DB::table('purchase_invoices')->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')->whereNotIn('purchase_invoices.status', ['cancelled'])->whereNotNull('purchase_invoices.invoice_date')->whereBetween('purchase_invoices.invoice_date', [$from, $to])->orderBy('purchase_invoices.invoice_date')->get() as $r) {
                $rows[] = ['date' => $r->invoice_date, 'ref' => $r->code, 'description' => 'Nhận HĐ mua: ' . $r->code, 'partner' => $r->name, 'debit' => 0.0, 'credit' => (float)$r->total];
            }
            foreach (DB::table('purchase_invoice_payments')->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')->whereBetween('purchase_invoice_payments.payment_date', [$from, $to])->orderBy('purchase_invoice_payments.payment_date')->get() as $r) {
                $rows[] = ['date' => $r->payment_date, 'ref' => $r->code, 'description' => 'Trả NCC / HĐ: ' . $r->code, 'partner' => $r->name, 'debit' => (float)$r->amount, 'credit' => 0.0];
            }
        } else {
            $opening = 0.0;
            $rows    = [];
        }
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        return [$opening, $rows];
    }

    public function headings(): array { return ['Ngày', 'Chứng từ', 'Diễn giải', 'Đối tác', 'Nợ', 'Có', 'Số dư']; }
    public function map($row): array  { return [$row->date, $row->ref, $row->description, $row->partner, $row->debit, $row->credit, $row->balance]; }
    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
