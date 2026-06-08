<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\ARAgingExport;
use App\Http\Controllers\Controller;
use App\Services\ArApLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ARAgingController extends Controller
{
    public function __construct(private ArApLedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'bucket', 'customer_id']);

        // Lấy tất cả items (kể cả đã thu) để tính summary đúng
        $allItems = $this->ledger->receivables($filters, onlyOutstanding: false);

        // Summary tính từ toàn bộ items (trước khi lọc bucket)
        $summary = $this->ledger->agingSummary($allItems);

        // Lọc bucket nếu có
        $bucket = $request->input('bucket');
        $filtered = $bucket
            ? $allItems->filter(fn ($r) => $r['bucket'] === $bucket)->values()
            : $allItems;

        // Phân trang
        $rows = $this->ledger->paginate($filtered, 30);

        return Inertia::render('Reports/AR/Index', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ARAgingExport($request->all()),
            'ar-aging-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
