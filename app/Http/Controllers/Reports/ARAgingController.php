<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\ARAgingExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ARAgingController extends Controller
{
    public function index(Request $request): Response
    {
        $search   = $request->input('search');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $bucket   = $request->input('bucket'); // overdue bucket filter

        $today = now()->toDateString();

        $query = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select([
                'invoices.id',
                'invoices.code',
                'invoices.issue_date',
                'invoices.due_date',
                'invoices.total',
                'invoices.status',
                'customers.id as customer_id',
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
            ->orderByDesc('invoices.id');

        $rows = $query->paginate(30);

        $rows->through(function ($row) use ($today, $bucket) {
            $total     = (float) $row->total;
            $paid      = (float) $row->paid;
            $remaining = max(0, $total - $paid);

            $daysOverdue = 0;
            if ($remaining > 0 && $row->due_date) {
                $daysOverdue = max(0, (int) now()->diffInDays($row->due_date, false) * -1);
            }

            $bucketLabel = $this->getBucket($daysOverdue, $remaining);

            return [
                'id'            => $row->id,
                'code'          => $row->code,
                'issue_date'    => $row->issue_date,
                'due_date'      => $row->due_date,
                'total'         => $total,
                'paid'          => $paid,
                'remaining'     => $remaining,
                'status'        => $row->status,
                'customer_id'   => $row->customer_id,
                'customer_name' => $row->customer_name,
                'days_overdue'  => $daysOverdue,
                'bucket'        => $bucketLabel,
            ];
        });

        // Filter by bucket after transform
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

        return Inertia::render('Reports/AR/Index', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['search', 'date_from', 'date_to', 'bucket']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ARAgingExport($request->all()),
            'ar-aging-' . now()->format('Ymd') . '.xlsx'
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
