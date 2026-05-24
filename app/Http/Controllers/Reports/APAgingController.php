<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\APAgingExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class APAgingController extends Controller
{
    public function index(Request $request): Response
    {
        $search   = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $bucket   = $request->input('bucket');

        $query = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->select([
                'purchase_invoices.id',
                'purchase_invoices.code',
                'purchase_invoices.invoice_date',
                'purchase_invoices.due_date',
                'purchase_invoices.total',
                'purchase_invoices.paid_amount',
                'purchase_invoices.status',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
            ])
            ->where('purchase_invoices.status', '!=', 'draft')
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('purchase_invoices.code', 'ilike', "%{$search}%")
                       ->orWhere('suppliers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('purchase_invoices.invoice_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('purchase_invoices.invoice_date', '<=', $dateTo))
            ->orderByDesc('purchase_invoices.id');

        $rows = $query->paginate(30);

        $rows->through(function ($row) {
            $total     = (float) $row->total;
            $paid      = (float) $row->paid_amount;
            $remaining = max(0, $total - $paid);

            $daysOverdue = 0;
            if ($remaining > 0 && $row->due_date) {
                $daysOverdue = max(0, (int) now()->diffInDays($row->due_date, false) * -1);
            }

            $bucket = $this->getBucket($daysOverdue, $remaining);

            return [
                'id'            => $row->id,
                'code'          => $row->code,
                'invoice_date'  => $row->invoice_date,
                'due_date'      => $row->due_date,
                'total'         => $total,
                'paid'          => $paid,
                'remaining'     => $remaining,
                'status'        => $row->status,
                'supplier_id'   => $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'days_overdue'  => $daysOverdue,
                'bucket'        => $bucket,
            ];
        });

        if ($bucket) {
            $filtered = collect($rows->items())->filter(fn ($r) => $r['bucket'] === $bucket)->values();
        } else {
            $filtered = collect($rows->items());
        }

        $summary = [
            'total_invoiced'  => $filtered->sum('total'),
            'total_paid'      => $filtered->sum('paid'),
            'total_remaining' => $filtered->sum('remaining'),
            'bucket_0'        => $filtered->where('bucket', 'Chưa đến hạn')->sum('remaining'),
            'bucket_1_30'     => $filtered->where('bucket', '1–30 ngày')->sum('remaining'),
            'bucket_31_60'    => $filtered->where('bucket', '31–60 ngày')->sum('remaining'),
            'bucket_61_90'    => $filtered->where('bucket', '61–90 ngày')->sum('remaining'),
            'bucket_90_plus'  => $filtered->where('bucket', '>90 ngày')->sum('remaining'),
        ];

        return Inertia::render('Reports/AP/Index', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['search', 'date_from', 'date_to', 'bucket']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new APAgingExport($request->all()),
            'ap-aging-' . now()->format('Ymd') . '.xlsx'
        );
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
