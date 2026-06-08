<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\APAgingExport;
use App\Http\Controllers\Controller;
use App\Services\ArApLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class APAgingController extends Controller
{
    public function __construct(private ArApLedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'bucket', 'supplier_id']);

        // Lấy tất cả items (kể cả đã trả) để tính summary đúng
        $allItems = $this->ledger->payables($filters, onlyOutstanding: false);

        // Summary tính từ toàn bộ items (trước khi lọc bucket)
        $summary = $this->ledger->agingSummary($allItems);

        // Lọc bucket nếu có
        $bucket = $request->input('bucket');
        $filtered = $bucket
            ? $allItems->filter(fn ($r) => $r['bucket'] === $bucket)->values()
            : $allItems;

        // Phân trang
        $rows = $this->ledger->paginate($filtered, 30);

        return Inertia::render('Reports/AP/Index', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new APAgingExport($request->all()),
            'ap-aging-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
