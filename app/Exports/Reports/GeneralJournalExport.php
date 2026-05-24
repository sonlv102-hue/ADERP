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

class GeneralJournalExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Nhật ký chung'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";

        $controller = new GeneralJournalController();
        $entries = [];

        // Invoices
        $invs = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->whereBetween('invoices.issue_date', [$from, $to])
            ->select('invoices.issue_date as date', 'invoices.code as ref', 'customers.name as partner', 'invoices.total')
            ->get();
        foreach ($invs as $r) {
            $entries[] = (object)['date' => $r->date, 'ref' => $r->ref, 'description' => 'Xuất HĐ bán: ' . $r->ref, 'partner' => $r->partner, 'debit_tk' => '131', 'credit_tk' => '511/3331', 'amount' => (float)$r->total];
        }

        // Payments received
        $pmts = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereBetween('payments.payment_date', [$from, $to])
            ->select('payments.payment_date as date', 'invoices.code as ref', 'customers.name as partner', 'payments.amount')
            ->get();
        foreach ($pmts as $r) {
            $entries[] = (object)['date' => $r->date, 'ref' => $r->ref, 'description' => 'Thu tiền / HĐ: ' . $r->ref, 'partner' => $r->partner, 'debit_tk' => '112', 'credit_tk' => '131', 'amount' => (float)$r->amount];
        }

        // Purchase invoices
        $piList = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNotIn('purchase_invoices.status', ['cancelled'])
            ->whereNotNull('purchase_invoices.invoice_date')
            ->whereBetween('purchase_invoices.invoice_date', [$from, $to])
            ->select('purchase_invoices.invoice_date as date', 'purchase_invoices.code as ref', 'suppliers.name as partner', 'purchase_invoices.total')
            ->get();
        foreach ($piList as $r) {
            $entries[] = (object)['date' => $r->date, 'ref' => $r->ref, 'description' => 'Nhận HĐ mua: ' . $r->ref, 'partner' => $r->partner, 'debit_tk' => '156/133', 'credit_tk' => '331', 'amount' => (float)$r->total];
        }

        // AP payments
        $appmts = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereBetween('purchase_invoice_payments.payment_date', [$from, $to])
            ->select('purchase_invoice_payments.payment_date as date', 'purchase_invoices.code as ref', 'suppliers.name as partner', 'purchase_invoice_payments.amount')
            ->get();
        foreach ($appmts as $r) {
            $entries[] = (object)['date' => $r->date, 'ref' => $r->ref, 'description' => 'Trả NCC / HĐ: ' . $r->ref, 'partner' => $r->partner, 'debit_tk' => '331', 'credit_tk' => '112', 'amount' => (float)$r->amount];
        }

        usort($entries, fn($a, $b) => strcmp($a->date, $b->date));

        return collect(array_map(fn($e, $i) => (object)array_merge((array)$e, ['seq' => $i + 1]), $entries, array_keys($entries)));
    }

    public function headings(): array
    {
        return ['STT', 'Ngày', 'Chứng từ', 'Diễn giải', 'Đối tác', 'TK Nợ', 'TK Có', 'Số tiền'];
    }

    public function map($row): array
    {
        return [$row->seq, $row->date, $row->ref, $row->description, $row->partner, $row->debit_tk, $row->credit_tk, $row->amount];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
