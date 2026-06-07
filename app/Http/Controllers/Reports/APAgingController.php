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

        // ── Summary from ALL matching invoices (không phân trang) ─────────────
        $summaryRows = $this->buildBaseQuery($search, $dateFrom, $dateTo)
            ->select([
                'purchase_invoices.total',
                'purchase_invoices.paid_amount',
                'purchase_invoices.due_date',
            ])
            ->get();

        $summary = [
            'total_invoiced'  => 0,
            'total_paid'      => 0,
            'total_remaining' => 0,
            'bucket_0'        => 0,
            'bucket_1_30'     => 0,
            'bucket_31_60'    => 0,
            'bucket_61_90'    => 0,
            'bucket_90_plus'  => 0,
        ];

        foreach ($summaryRows as $r) {
            $total     = (float) $r->total;
            $paid      = (float) $r->paid_amount;
            $remaining = max(0.0, $total - $paid);
            $daysOverdue = 0;
            if ($remaining > 0 && $r->due_date) {
                $daysOverdue = max(0, (int) now()->diffInDays($r->due_date, false) * -1);
            }
            $bl = $this->getBucket($daysOverdue, $remaining);

            $summary['total_invoiced']  += $total;
            $summary['total_paid']      += $paid;
            $summary['total_remaining'] += $remaining;
            match ($bl) {
                'Chưa đến hạn' => $summary['bucket_0']       += $remaining,
                '1–30 ngày'    => $summary['bucket_1_30']    += $remaining,
                '31–60 ngày'   => $summary['bucket_31_60']   += $remaining,
                '61–90 ngày'   => $summary['bucket_61_90']   += $remaining,
                '>90 ngày'     => $summary['bucket_90_plus'] += $remaining,
                default        => null,
            };
        }

        // ── Paginated table rows ───────────────────────────────────────────────
        $tableQuery = $this->buildBaseQuery($search, $dateFrom, $dateTo)
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
            ]);

        $rows = $tableQuery->paginate(30);

        $rows->through(function ($row) {
            $total     = (float) $row->total;
            $paid      = (float) $row->paid_amount;
            $remaining = max(0, $total - $paid);
            $daysOverdue = 0;
            if ($remaining > 0 && $row->due_date) {
                $daysOverdue = max(0, (int) now()->diffInDays($row->due_date, false) * -1);
            }
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
                'bucket'        => $this->getBucket($daysOverdue, $remaining),
            ];
        });

        if ($bucket) {
            $tableItems = collect($rows->items())->filter(fn ($r) => $r['bucket'] === $bucket)->values();
            $rows->setCollection($tableItems);
        }

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

    private function buildBaseQuery(?string $search, ?string $dateFrom, ?string $dateTo)
    {
        return DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNotIn('purchase_invoices.status', ['draft', 'cancelled'])
            ->when($search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('purchase_invoices.code', 'ilike', "%{$search}%")
                       ->orWhere('suppliers.name', 'ilike', "%{$search}%")
                )
            )
            ->when($dateFrom, fn ($q) => $q->where('purchase_invoices.invoice_date', '>=', $dateFrom))
            ->when($dateTo,   fn ($q) => $q->where('purchase_invoices.invoice_date', '<=', $dateTo))
            ->orderByDesc('purchase_invoices.id');
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
