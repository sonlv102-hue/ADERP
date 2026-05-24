<?php

namespace App\Exports\Reports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ARAgingExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        return 'Công nợ phải thu';
    }

    public function collection()
    {
        $search   = $this->filters['search']    ?? null;
        $dateFrom = $this->filters['date_from'] ?? null;
        $dateTo   = $this->filters['date_to']   ?? null;

        return DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select([
                'invoices.code',
                'invoices.issue_date',
                'invoices.due_date',
                'invoices.total',
                'invoices.status',
                'customers.name as customer_name',
                DB::raw("COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = invoices.id), 0) as paid"),
            ])
            ->where('invoices.status', '!=', 'draft')
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('invoices.code', 'ilike', "%{$search}%")
                       ->orWhere('customers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('invoices.issue_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('invoices.issue_date', '<=', $dateTo))
            ->orderByDesc('invoices.id')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Số hóa đơn', 'Khách hàng', 'Ngày xuất', 'Hạn thanh toán',
            'Tổng tiền', 'Đã thu', 'Còn lại', 'Trạng thái', 'Tình trạng nợ',
        ];
    }

    public function map($row): array
    {
        $total     = (float) $row->total;
        $paid      = (float) $row->paid;
        $remaining = max(0, $total - $paid);

        $daysOverdue = 0;
        if ($remaining > 0 && $row->due_date) {
            $daysOverdue = max(0, (int) now()->diffInDays($row->due_date, false) * -1);
        }

        $bucket = $this->getBucket($daysOverdue, $remaining);

        return [
            $row->code,
            $row->customer_name,
            $row->issue_date,
            $row->due_date ?? '—',
            $total,
            $paid,
            $remaining,
            $row->status,
            $bucket,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function getBucket(int $daysOverdue, float $remaining): string
    {
        if ($remaining <= 0) return 'Đã thanh toán';
        if ($daysOverdue <= 0) return 'Chưa đến hạn';
        if ($daysOverdue <= 30) return '1–30 ngày';
        if ($daysOverdue <= 60) return '31–60 ngày';
        if ($daysOverdue <= 90) return '61–90 ngày';
        return '>90 ngày';
    }
}
